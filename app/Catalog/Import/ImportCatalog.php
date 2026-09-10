<?php

namespace App\Catalog\Import;

use Illuminate\Support\Facades\DB;
use XMLReader;

/**
 * Fills the bl_* tables from a catalog archive.
 *
 * Idempotent: every table is emptied first, so re-running against the same
 * release leaves the same data. The collection is untouched — instances keep
 * the inventory they were created with.
 */
class ImportCatalog
{
    /** Item types worth importing. I, O and U never appear inside inventories. */
    private const TYPES = ['S', 'P', 'M', 'G', 'B', 'C'];

    /** @var array<string, int> colour name => id, for part_color_codes.xml */
    private array $colorIdsByName = [];

    /** @var array<string, int> theme path => id */
    private array $themeIds = [];

    /** @var array<string, array<string, int>> type => (item id => theme id) */
    private array $itemThemes = [];

    private $progress = null;

    /** @var array<string, float> step name => seconds */
    private array $timings = [];

    private ?string $currentStep = null;

    private float $stepStarted = 0.0;

    public function handle(CatalogArchive $archive, ?callable $progress = null): array
    {
        $this->progress = $progress;
        $counts = [];

        DB::transaction(function () use ($archive, &$counts) {
            $this->truncate();

            $counts['item_types'] = $this->importItemTypes($archive);
            $counts['colors'] = $this->importColors($archive);
            $counts['categories'] = $this->importCategories($archive);
            $counts['themes'] = $this->importThemes($archive);
            $counts['items'] = $this->importItems($archive);
            $counts['inventories'] = $this->importInventories($archive);
            $counts['element_codes'] = $this->importElementCodes($archive);
            $counts['changelog'] = $this->importChangelog($archive);
            $counts['zip_index'] = $this->importChecksums($archive);

            $this->writeMeta($archive);
            $this->closeStep();
        });

        return $counts;
    }

    /** @return array<string, float> seconds spent per step, slowest first */
    public function timings(): array
    {
        arsort($this->timings);

        return $this->timings;
    }

    private function step(string $message): void
    {
        $this->closeStep();
        $this->currentStep = $message;
        $this->stepStarted = microtime(true);

        if ($this->progress) {
            ($this->progress)($message);
        }
    }

    private function closeStep(): void
    {
        if ($this->currentStep !== null) {
            $this->timings[$this->currentStep] = microtime(true) - $this->stepStarted;
            $this->currentStep = null;
        }
    }

    /**
     * Writes a list of uniform associative rows through BulkInsert.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function bulk(string $table, array $rows): void
    {
        if (! $rows) {
            return;
        }

        $insert = new BulkInsert($table, array_keys($rows[0]));

        foreach ($rows as $row) {
            $insert->add(array_values($row));
        }

        $insert->flush();
    }

    private function truncate(): void
    {
        foreach ([
            'bl_zip_index', 'bl_changelog', 'bl_element_codes', 'bl_inventory',
            'bl_item_alt_ids', 'bl_items_fts', 'bl_items', 'bl_themes',
            'bl_categories', 'bl_colors', 'bl_item_types', 'bl_meta',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * BrickLink escapes item names twice: the file holds
     * "Playhouse &amp;#40;Play House&amp;#41;", XML parsing turns that into
     * "Playhouse &#40;Play House&#41;", and only a second decode yields
     * "Playhouse (Play House)". 76,331 names are affected.
     */
    private function decode(string $value): string
    {
        return html_entity_decode(
            html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
    }

    /**
     * Walks <ITEM> elements of a catalog XML, yielding each as a field map.
     *
     * XMLReader rather than a regular expression: matching over the 12 MB
     * items/P.xml peaks at about 150 MB, which is more than an add-on
     * container should need.
     *
     * @return iterable<array<string, string>>
     */
    private function elements(string $xml, string $wrapper = 'ITEM'): iterable
    {
        $reader = new XMLReader;
        $reader->XML($xml, 'UTF-8', LIBXML_COMPACT | LIBXML_PARSEHUGE);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== $wrapper) {
                continue;
            }

            $fields = [];
            $depth = $reader->depth;

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT
                    && $reader->name === $wrapper
                    && $reader->depth === $depth) {
                    break;
                }

                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $field = $reader->name;
                    $fields[$field] = $reader->isEmptyElement ? '' : $reader->readString();
                }
            }

            yield $fields;
        }

        $reader->close();
    }

    private function importItemTypes(CatalogArchive $archive): int
    {
        $this->step('item types');
        $rows = [];

        foreach ($this->elements($archive->require('itemtypes.xml')) as $item) {
            $rows[] = [
                'code' => $item['ITEMTYPE'],
                'name' => $this->decode($item['ITEMTYPENAME'] ?? $item['ITEMTYPE']),
            ];
        }

        DB::table('bl_item_types')->insert($rows);

        return count($rows);
    }

    private function importColors(CatalogArchive $archive): int
    {
        $this->step('colors');
        $rows = [];

        foreach ($this->elements($archive->require('colors.xml')) as $item) {
            $id = (int) $item['COLOR'];
            $name = $this->decode($item['COLORNAME']);
            $this->colorIdsByName[$name] = $id;

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'rgb' => ($item['COLORRGB'] ?? '') !== '' ? $item['COLORRGB'] : null,
                'type' => $item['COLORTYPE'] ?? null,
                // ldraw_rgb stays null: LDConfig.ldr uses LDraw colour numbers,
                // and mapping those onto BrickLink ids is a separate job.
                'ldraw_rgb' => null,
                'year_from' => (int) ($item['COLORYEARFROM'] ?? 0) ?: null,
                'year_to' => (int) ($item['COLORYEARTO'] ?? 0) ?: null,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('bl_colors')->insert($chunk);
        }

        return count($rows);
    }

    private function importCategories(CatalogArchive $archive): int
    {
        $this->step('categories');
        $rows = [];

        foreach ($this->elements($archive->require('categories.xml')) as $item) {
            $rows[] = [
                'id' => (int) $item['CATEGORY'],
                'name' => $this->decode($item['CATEGORYNAME']),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('bl_categories')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Themes exist only in items/*.csv, as full paths like
     * "Town / Classic Town / Supplemental". categories.xml carries the root
     * name alone, so the tree has to be rebuilt from those strings.
     */
    private function importThemes(CatalogArchive $archive): int
    {
        $this->step('themes');
        $insert = new BulkInsert('bl_themes', [
            'id', 'parent_id', 'root_category_id', 'name', 'path', 'depth',
        ]);
        $nextId = 1;

        foreach (self::TYPES as $type) {
            $csv = $archive->get("items/{$type}.csv");

            if ($csv === null) {
                continue;
            }

            foreach (explode("\n", $csv) as $line) {
                $columns = explode("\t", rtrim($line, "\r"));

                if (count($columns) < 4 || ! ctype_digit($columns[0])) {
                    continue;
                }

                [$rootCategory, $path, $number] = $columns;
                $path = $this->decode(trim($path));

                if ($path === '') {
                    continue;
                }

                $parentId = null;
                $walked = [];

                foreach (explode(' / ', $path) as $depth => $segment) {
                    $walked[] = $segment;
                    $key = implode(' / ', $walked);

                    if (! isset($this->themeIds[$key])) {
                        $this->themeIds[$key] = $nextId++;
                        $insert->add([
                            $this->themeIds[$key],
                            $parentId,
                            (int) $rootCategory,
                            $segment,
                            $key,
                            $depth,
                        ]);
                    }

                    $parentId = $this->themeIds[$key];
                }

                $this->itemThemes[$type][$number] = $parentId;
            }
        }

        $insert->flush();

        return $insert->rows();
    }

    private function importItems(CatalogArchive $archive): int
    {
        $withInventory = $this->inventoryList($archive);
        $total = 0;

        foreach (self::TYPES as $type) {
            $xml = $archive->get("items/{$type}.xml");

            if ($xml === null) {
                continue;
            }

            $this->step("items {$type}");

            // Rows go straight into the buffers instead of being collected
            // first: holding all 96,620 parts as arrays costs well over a
            // hundred megabytes, and this has to run on a Raspberry Pi.
            $items = new BulkInsert('bl_items', [
                'type', 'id', 'name', 'category_id', 'theme_id',
                'year', 'weight', 'image_color_id', 'has_inventory',
            ]);
            $search = new BulkInsert('bl_items_fts', ['name', 'type', 'item_id']);
            $altIds = new BulkInsert('bl_item_alt_ids', ['type', 'id', 'alt_id']);

            foreach ($this->elements($xml) as $item) {
                $id = $item['ITEMID'];
                $name = $this->decode($item['ITEMNAME']);

                $items->add([
                    $type,
                    $id,
                    $name,
                    (int) ($item['CATEGORY'] ?? 0) ?: null,
                    $this->itemThemes[$type][$id] ?? null,
                    (int) ($item['ITEMYEAR'] ?? 0) ?: null,
                    (float) ($item['ITEMWEIGHT'] ?? 0) ?: null,
                    (int) ($item['IMAGECOLOR'] ?? 0),
                    isset($withInventory[$type.'/'.$id]) ? 1 : 0,
                ]);

                $search->add([$name, $type, $id]);

                foreach (preg_split('/\s*,\s*/', $item['ALTITEMIDS'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $alt) {
                    $altIds->add([$type, $id, $alt]);
                }
            }

            $items->flush();
            $search->flush();
            $altIds->flush();

            $total += $items->rows();
        }

        return $total;
    }

    /**
     * btinvlist.csv lists every item that has an inventory: type, id, and a
     * last-changed date that is empty for two thirds of the rows.
     *
     * @return array<string, true>
     */
    private function inventoryList(CatalogArchive $archive): array
    {
        $list = [];
        $csv = $archive->get('btinvlist.csv');

        foreach (explode("\n", (string) $csv) as $line) {
            $columns = explode("\t", rtrim($line, "\r"));

            if (count($columns) >= 2 && $columns[0] !== '') {
                $list[$columns[0].'/'.$columns[1]] = true;
            }
        }

        return $list;
    }

    /**
     * Inventories are 53,137 small, machine-generated files, one per item.
     * A regular expression is the right tool here: the shape never varies and
     * each file is a couple of kilobytes.
     */
    private function importInventories(CatalogArchive $archive): int
    {
        $this->step('inventories');

        $pattern = '#<ITEMTYPE>(\w)</ITEMTYPE>\s*<ITEMID>(.*?)</ITEMID>\s*<QTY>(-?\d+)</QTY>\s*'
            .'<COLOR>(\d+)</COLOR>\s*<EXTRA>(\w)</EXTRA>\s*<ALTERNATE>(\w)</ALTERNATE>\s*'
            .'<MATCHID>(\d+)</MATCHID>\s*<COUNTERPART>(\w)</COUNTERPART>#s';

        // Building the two indexes afterwards rather than maintaining them row
        // by row: measured 4.8 s + 6.0 s against 13.4 s for the same rows.
        DB::statement('DROP INDEX IF EXISTS ix_inv_parent');
        DB::statement('DROP INDEX IF EXISTS ix_inv_child');

        $insert = new BulkInsert('bl_inventory', [
            'parent_type', 'parent_id', 'child_type', 'child_id', 'color_id',
            'qty', 'is_extra', 'is_alternate', 'match_id', 'is_counterpart',
        ]);

        $isInventory = fn (string $name) => (bool) preg_match('#^[SPMGBC]/.+\.xml$#', $name);

        foreach ($archive->each($isInventory) as $name => $xml) {
            [$parentType, $parentId] = explode('/', substr($name, 0, -4), 2);

            preg_match_all($pattern, $xml, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $insert->add([
                    $parentType,
                    $parentId,
                    $match[1],
                    $match[2],
                    (int) $match[4],
                    (int) $match[3],
                    $match[5] === 'Y' ? 1 : 0,
                    $match[6] === 'Y' ? 1 : 0,
                    (int) $match[7],
                    $match[8] === 'Y' ? 1 : 0,
                ]);
            }
        }

        $insert->flush();

        $this->step('inventory indexes');
        DB::statement('CREATE INDEX ix_inv_parent ON bl_inventory(parent_type, parent_id)');
        DB::statement('CREATE INDEX ix_inv_child  ON bl_inventory(child_type, child_id, color_id)');

        return $insert->rows();
    }

    /**
     * part_color_codes.xml names the colour as a string. Resolve it to an id
     * during import so nothing has to join on text at runtime.
     */
    private function importElementCodes(CatalogArchive $archive): int
    {
        $this->step('element codes');

        $insert = new BulkInsert('bl_element_codes', ['item_type', 'item_id', 'color_id', 'code']);

        foreach ($this->elements($archive->require('part_color_codes.xml')) as $item) {
            $insert->add([
                $item['ITEMTYPE'],
                $item['ITEMID'],
                $this->colorIdsByName[$this->decode($item['COLOR'])] ?? null,
                $item['CODENAME'],
            ]);
        }

        $insert->flush();

        return $insert->rows();
    }

    /**
     * btchglog.csv: id, date, kind, then the old and new type/id pair.
     * Its text is HTML-escaped in the raw file, unlike the XML files.
     */
    private function importChangelog(CatalogArchive $archive): int
    {
        $this->step('changelog');
        $rows = [];

        foreach (explode("\n", (string) $archive->get('btchglog.csv')) as $line) {
            $columns = explode("\t", rtrim($line, "\r"));

            if (count($columns) < 7 || ! ctype_digit($columns[0])) {
                continue;
            }

            $rows[] = [
                'id' => (int) $columns[0],
                'changed_at' => $this->normaliseDate($columns[1]),
                'kind' => $columns[2],
                'from_type' => $columns[3],
                'from_id' => html_entity_decode($columns[4], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'to_type' => $columns[5],
                'to_id' => html_entity_decode($columns[6], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        // Keyed by id first: the file occasionally repeats one, and id is the
        // primary key.
        $rows = collect($rows)->keyBy('id')->values()->all();
        $this->bulk('bl_changelog', $rows);

        return count($rows);
    }

    /** BrickLink writes M/D/YYYY; store the sortable form. */
    private function normaliseDate(string $value): ?string
    {
        $parts = explode('/', trim($value));

        if (count($parts) !== 3) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $parts[2], (int) $parts[0], (int) $parts[1]);
    }

    private function importChecksums(CatalogArchive $archive): int
    {
        $this->step('checksums');
        $rows = [];

        foreach ($archive->checksums() as $name => $crc) {
            $rows[] = ['name' => $name, 'crc32' => $crc];
        }

        $this->bulk('bl_zip_index', $rows);

        return count($rows);
    }

    private function writeMeta(CatalogArchive $archive): void
    {
        DB::table('bl_meta')->insert([
            ['key' => 'imported_at', 'value' => now()->toIso8601String()],
            ['key' => 'archive_path', 'value' => $archive->path()],
            ['key' => 'archive_size', 'value' => (string) filesize($archive->path())],
        ]);
    }
}
