<?php

namespace Tests\Feature;

use App\Catalog\Import\CatalogArchive;
use App\Catalog\Import\ImportCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/**
 * Imports a miniature archive shaped exactly like a brickstore-database
 * release, so the awkward parts of the real data are covered without carrying
 * a 38 MB fixture into the repository.
 */
class ImportCatalogTest extends TestCase
{
    use RefreshDatabase;

    private string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archivePath = tempnam(sys_get_temp_dir(), 'catalog').'.zip';
        $this->buildArchive($this->archivePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->archivePath);

        parent::tearDown();
    }

    private function import(): array
    {
        $archive = new CatalogArchive($this->archivePath);

        try {
            return app(ImportCatalog::class)->handle($archive);
        } finally {
            $archive->close();
        }
    }

    public function test_it_imports_every_section(): void
    {
        $counts = $this->import();

        $this->assertSame(3, $counts['item_types']);
        $this->assertSame(2, $counts['colors']);
        $this->assertSame(3, $counts['categories']);
        $this->assertSame(3, $counts['items']);
        $this->assertSame(4, $counts['inventories']);
        $this->assertSame(1, $counts['element_codes']);
        $this->assertSame(1, $counts['changelog']);
    }

    /**
     * The single most likely thing to get wrong: BrickLink escapes item names
     * twice, so a single decode leaves "&#40;" sitting in the database.
     */
    public function test_it_decodes_doubly_escaped_names(): void
    {
        $this->import();

        $this->assertSame(
            'Playhouse (Play House)',
            DB::table('bl_items')->where('id', '0041-2')->value('name'),
        );

        $this->assertSame(
            0,
            DB::table('bl_items')->where('name', 'like', '%&#%')->count(),
            'no name may still carry an HTML entity',
        );
    }

    /**
     * Subtheme paths live only in items/*.csv; the XML and categories.xml
     * carry the root name alone.
     */
    public function test_it_rebuilds_the_theme_tree_from_csv_paths(): void
    {
        $this->import();

        // A three-segment path becomes three nodes, one per level.
        $paths = DB::table('bl_themes')->where('path', 'like', 'Town%')->orderBy('id')->pluck('path')->all();

        $this->assertSame(['Town', 'Town / Classic Town', 'Town / Classic Town / Supplemental'], $paths);

        // A single-segment path becomes exactly one node, not a duplicate root.
        $this->assertSame(1, DB::table('bl_themes')->where('path', 'DUPLO')->count());

        $leaf = DB::table('bl_themes')->where('path', 'Town / Classic Town / Supplemental')->first();
        $this->assertSame(2, $leaf->depth);
        $this->assertSame(
            $leaf->id,
            DB::table('bl_items')->where('id', '0011-2')->value('theme_id'),
        );
    }

    /** Spares, alternates and counterparts must survive with their flags. */
    public function test_it_keeps_inventory_flags(): void
    {
        $this->import();

        $rows = DB::table('bl_inventory')->where('parent_id', '0011-2')->get()->keyBy('child_id');

        $this->assertSame(0, (int) $rows['3001']->is_extra);
        $this->assertSame(1, (int) $rows['3005']->is_extra);
        $this->assertSame(1, (int) $rows['3006']->is_alternate);
        $this->assertSame(7, (int) $rows['3006']->match_id);
        $this->assertSame(1, (int) $rows['sw0396']->is_counterpart);
    }

    public function test_it_marks_items_that_have_an_inventory(): void
    {
        $this->import();

        $this->assertSame(1, (int) DB::table('bl_items')->where('id', '0011-2')->value('has_inventory'));
        $this->assertSame(0, (int) DB::table('bl_items')->where('id', '0041-2')->value('has_inventory'));
    }

    /** part_color_codes.xml names the colour; the id has to be resolved. */
    public function test_it_resolves_element_code_colours_to_ids(): void
    {
        $this->import();

        $code = DB::table('bl_element_codes')->where('code', '4221514')->first();

        $this->assertSame(11, (int) $code->color_id);
    }

    public function test_it_records_archive_checksums_for_incremental_updates(): void
    {
        $this->import();

        $this->assertSame(
            DB::table('bl_zip_index')->count(),
            DB::table('bl_zip_index')->distinct()->count('name'),
        );
        $this->assertNotNull(DB::table('bl_zip_index')->where('name', 'colors.xml')->value('crc32'));
    }

    /** Re-running against the same release must change nothing. */
    public function test_it_is_idempotent(): void
    {
        $first = $this->import();
        $second = $this->import();

        $this->assertSame($first, $second);
        $this->assertSame(3, DB::table('bl_items')->count());
        $this->assertSame(4, DB::table('bl_inventory')->count());
    }

    private function buildArchive(string $path): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('itemtypes.xml', $this->catalog([
            ['ITEMTYPE' => 'S', 'ITEMTYPENAME' => 'Set'],
            ['ITEMTYPE' => 'P', 'ITEMTYPENAME' => 'Part'],
            ['ITEMTYPE' => 'M', 'ITEMTYPENAME' => 'Minifigure'],
        ]));

        $zip->addFromString('colors.xml', $this->catalog([
            ['COLOR' => '0', 'COLORNAME' => '(Not Applicable)', 'COLORRGB' => '', 'COLORTYPE' => 'N/A'],
            ['COLOR' => '11', 'COLORNAME' => 'Black', 'COLORRGB' => '2E2E2E', 'COLORTYPE' => 'Solid'],
        ]));

        $zip->addFromString('categories.xml', $this->catalog([
            ['CATEGORY' => '5', 'CATEGORYNAME' => 'Brick'],
            ['CATEGORY' => '67', 'CATEGORYNAME' => 'Town'],
            ['CATEGORY' => '167', 'CATEGORYNAME' => 'DUPLO'],
        ]));

        // Names arrive escaped twice, exactly as BrickLink writes them.
        $zip->addFromString('items/S.xml', $this->catalog([
            ['ITEMTYPE' => 'S', 'ITEMID' => '0011-2', 'ITEMNAME' => 'LEGOLAND Mini-Figures',
                'CATEGORY' => '67', 'ITEMYEAR' => '1982', 'ITEMWEIGHT' => '', 'IMAGECOLOR' => '0'],
            ['ITEMTYPE' => 'S', 'ITEMID' => '0041-2', 'ITEMNAME' => 'Playhouse &amp;#40;Play House&amp;#41;',
                'CATEGORY' => '167', 'ITEMYEAR' => '1975', 'ITEMWEIGHT' => '', 'IMAGECOLOR' => '0'],
        ]));

        $zip->addFromString('items/S.csv', implode("\n", [
            "Category ID\tCategory Name\tNumber\tName",
            '',
            '',
            "67\tTown / Classic Town / Supplemental\t0011-2\tLEGOLAND Mini-Figures",
            "167\tDUPLO\t0041-2\tPlayhouse",
        ]));

        $zip->addFromString('items/P.xml', $this->catalog([
            ['ITEMTYPE' => 'P', 'ITEMID' => '3001', 'ITEMNAME' => 'Brick 2 x 4',
                'ALTITEMIDS' => '3001old, 3001b', 'CATEGORY' => '5', 'ITEMWEIGHT' => '2.5', 'IMAGECOLOR' => '11'],
        ]));
        $zip->addFromString('items/P.csv', "Category ID\tCategory Name\tNumber\tName\n\n\n5\tBrick\t3001\tBrick 2 x 4");

        $zip->addFromString('S/0011-2.xml', $this->inventory([
            ['P', '3001', 4, 11, 'N', 'N', 0, 'N'],
            ['P', '3005', 2, 11, 'Y', 'N', 0, 'N'],   // spare
            ['P', '3006', 1, 11, 'N', 'Y', 7, 'N'],   // alternate of match group 7
            ['M', 'sw0396', 1, 0, 'N', 'N', 0, 'Y'],  // counterpart
        ]));

        $zip->addFromString('btinvlist.csv', "S\t0011-2\t3/12/2024 7:41:02 AM");

        $zip->addFromString('part_color_codes.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<CODES>\n"
            ."   <ITEM>\n      <ITEMTYPE>P</ITEMTYPE>\n      <ITEMID>44</ITEMID>\n"
            ."      <COLOR>Black</COLOR>\n      <CODENAME>4221514</CODENAME>\n   </ITEM>\n</CODES>\n");

        $zip->addFromString('btchglog.csv', "461584\t9/9/2026\tI\tP\tbb1388c01pb01\tP\t109758c01pb01");

        $zip->close();
    }

    private function catalog(array $items): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<CATALOG>\n";

        foreach ($items as $item) {
            $xml .= "   <ITEM>\n";
            foreach ($item as $tag => $value) {
                $xml .= "      <{$tag}>{$value}</{$tag}>\n";
            }
            $xml .= "   </ITEM>\n";
        }

        return $xml."</CATALOG>\n";
    }

    private function inventory(array $rows): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<INVENTORY>\n";

        foreach ($rows as [$type, $id, $qty, $color, $extra, $alternate, $matchId, $counterpart]) {
            $xml .= "   <ITEM>\n      <ITEMTYPE>{$type}</ITEMTYPE>\n      <ITEMID>{$id}</ITEMID>\n"
                ."      <QTY>{$qty}</QTY>\n      <COLOR>{$color}</COLOR>\n"
                ."      <EXTRA>{$extra}</EXTRA>\n      <ALTERNATE>{$alternate}</ALTERNATE>\n"
                ."      <MATCHID>{$matchId}</MATCHID>\n      <COUNTERPART>{$counterpart}</COUNTERPART>\n"
                ."   </ITEM>\n";
        }

        return $xml."</INVENTORY>\n";
    }
}
