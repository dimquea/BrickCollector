<?php

namespace App\Collection\Actions;

use App\Catalog\Models\Item as CatalogItem;
use App\Catalog\Queries\ItemInventory;
use App\Collection\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Пересобирает опись экземпляров по нынешнему справочнику.
 *
 * Опись копируется в коллекцию один раз, при добавлении, и дальше живёт сама
 * по себе: физический набор не меняется оттого, что BrickLink поправил свою
 * страницу. Но иногда поправка и есть правда — в наборе нашли недостающую
 * позицию, разделили парные детали, уточнили цвет, — и тогда экземпляр хочется
 * привести к свежему описанию. Руками, а не само собой: только владелец знает,
 * что перед ним на полке.
 *
 * Пересобирается ровно то, что пришло из справочника. Единственное, что в
 * дереве ввёл человек, — отметки о недостаче и комментарии у фигурок и
 * поднаборов, и они переносятся на свои места по пути от корня: тип, артикул, цвет и флаги позиции, а внутри одинаковых —
 * порядковый номер. Устойчивого идентификатора у строки описи не существует,
 * в bl_inventory нет даже ключа, поэтому путь — единственное, чем строку можно
 * узнать после пересборки.
 *
 * Сборки и свободные партии не трогаются вовсе: их содержимое никто не
 * описывал в справочнике, оно собрано руками.
 */
class RefreshInventories
{
    public function __construct(
        private readonly ItemInventory $inventory,
        private readonly WriteContents $contents,
        private readonly RecalculateEntryFlags $flags,
    ) {}

    /**
     * @return array{entries: int, changed: int, added: int, removed: int, losses: int,
     *     orphaned: int, orphans: array<int, array<string, mixed>>}
     */
    public function handle(): array
    {
        $summary = ['entries' => 0, 'changed' => 0, 'added' => 0, 'removed' => 0, 'losses' => 0, 'orphaned' => 0];
        $orphans = [];

        $entries = Entry::query()
            ->whereIn('item_type', ItemInventory::EXPANDABLE)
            ->orderBy('id')
            ->get();

        // Одна сделка на весь прогон, а не на экземпляр: записей под тысячу, и
        // каждая отдельная фиксация на SQLite стоит дороже самой работы — в
        // замере это была разница между секундой и дюжиной. Заодно и всё
        // правило целиком: обновление либо прошло, либо коллекция осталась
        // ровно такой, какой была.
        $summary = DB::transaction(function () use ($entries, $summary, &$orphans) {
            foreach ($entries as $entry) {
                $summary['entries']++;

                $result = $this->one($entry);

                $orphans = [...$orphans, ...$result['orphans']];
                unset($result['orphans']);

                foreach ($result as $key => $value) {
                    $summary[$key] += $value;
                }
            }

            return $summary;
        });

        return $summary + ['orphans' => $this->describe($orphans)];
    }

    /**
     * Один экземпляр.
     *
     * @return array{changed: int, added: int, removed: int, losses: int, orphaned: int,
     *     orphans: array<int, array<string, mixed>>}
     */
    private function one(Entry $entry): array
    {
        $item = CatalogItem::where('type', $entry->item_type)->where('id', $entry->item_id)->first();

        $tree = $item?->has_inventory
            ? $this->inventory->tree($entry->item_type, $entry->item_id)
            : [];

        // Пустая опись — не повод опустошить набор. Справочник пересобирают
        // каждый раз заново, и предмет, которого в свежем выпуске не оказалось
        // или у которого пропала отметка об описи, выглядел бы здесь как набор
        // без единой детали. Такой экземпляр оставляем как есть.
        if ($tree === []) {
            return ['changed' => 0, 'added' => 0, 'removed' => 0, 'losses' => 0, 'orphaned' => 0, 'orphans' => []];
        }

        $before = $this->keyed($entry);
        $losses = array_filter(array_map(fn (object $row) => (int) $row->lost_qty, $before));
        $notes = array_filter(array_map(fn (object $row) => $row->note, $before), fn ($note) => $note !== null);

        $run = function () use ($entry, $tree, $before, $losses, $notes) {
            $root = $this->root($before);

            $entry->items()->delete();

            if ($entry->item_type === 'S') {
                // Набор и есть экземпляр: его позиции ложатся в корень.
                $this->contents->store($entry, $tree, null, null, 1, true);
            } else {
                // Количество и цвет корневой строки ввёл человек — сколько
                // таких фигурок у него и в каком цвете деталь, — справочник
                // об этом ничего не знает.
                $qty = max(1, (int) ($root->qty ?? 1));

                $rootId = $this->contents->root(
                    $entry,
                    $entry->item_type,
                    $entry->item_id,
                    (int) ($root->color_id ?? 0),
                    $qty,
                );

                $this->contents->store($entry, $tree, $rootId, $entry->item_type, $qty, true);
            }

            $after = $this->keyed($entry);

            $moved = 0;
            $orphans = [];

            foreach (array_keys($losses + $notes) as $key) {
                $lost = $losses[$key] ?? 0;
                $note = $notes[$key] ?? null;

                if (! isset($after[$key])) {
                    // Позиции, на которой стояла отметка, в новой описи нет.
                    // Молча потерять её нельзя: это единственное, что в дереве
                    // ввёл человек, и он должен узнать, что именно пропало.
                    $row = $before[$key];

                    $orphans[] = [
                        'entry_id' => $entry->id,
                        'entry' => $entry->item_id,
                        'type' => $row->item_type,
                        'item_id' => $row->item_id,
                        'color_id' => (int) $row->color_id,
                        'lost' => $lost,
                        'note' => $note,
                    ];

                    continue;
                }

                // Недостача не может быть больше того, что есть: позиция могла
                // ужаться, и «не хватает трёх из двух» — не число, а ошибка.
                DB::table('collection_items')
                    ->where('id', $after[$key]->id)
                    ->update([
                        'lost_qty' => min($lost, (int) $after[$key]->qty),
                        'note' => $note,
                    ]);

                $moved += $lost > 0 ? 1 : 0;
            }

            $this->flags->handle($entry->fresh());

            $added = count(array_diff_key($after, $before));
            $removed = count(array_diff_key($before, $after));

            $changed = $added > 0 || $removed > 0 || $this->quantitiesDiffer($before, $after);

            return [
                'changed' => $changed ? 1 : 0,
                'added' => $added,
                'removed' => $removed,
                'losses' => $moved,
                'orphaned' => count($orphans),
                'orphans' => $orphans,
            ];
        };

        return $run();
    }

    /**
     * Дополняет осиротевшие отметки названиями.
     *
     * Одним запросом на весь прогон, а не по строке на каждую: таких отметок
     * обычно единицы, но запрос внутри цикла остаётся запросом внутри цикла.
     *
     * @param  array<int, array<string, mixed>>  $orphans
     * @return array<int, array<string, mixed>>
     */
    private function describe(array $orphans): array
    {
        if ($orphans === []) {
            return [];
        }

        $names = DB::table('bl_items')
            ->whereIn('id', array_column($orphans, 'item_id'))
            ->get(['type', 'id', 'name'])
            ->keyBy(fn (object $row) => $row->type.'/'.$row->id);

        $colours = DB::table('bl_colors')
            ->whereIn('id', array_filter(array_column($orphans, 'color_id')))
            ->pluck('name', 'id');

        return array_map(fn (array $orphan) => $orphan + [
            'name' => $names[$orphan['type'].'/'.$orphan['item_id']]->name ?? $orphan['item_id'],
            'color' => $colours[$orphan['color_id']] ?? null,
        ], $orphans);
    }

    /** Корневая строка экземпляра: у набора её нет, у всего прочего одна. */
    private function root(array $rows): ?object
    {
        foreach ($rows as $row) {
            if ($row->parent_id === null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Строки экземпляра, разложенные по путям.
     *
     * Путь строится от корня и на каждом шаге несёт всё, чем позиция
     * отличается от соседней: артикул и цвет — чтобы узнать саму вещь, флаги и
     * match_id — потому что одна и та же деталь того же цвета законно стоит в
     * наборе и как обычная, и как запасная, и как альтернатива, а это разные
     * строки. Порядковый номер нужен для настоящих близнецов: BrickLink
     * изредка перечисляет одинаковые позиции дважды.
     *
     * Количество в путь не входит: именно оно и должно меняться.
     *
     * @return array<string, object> путь => строка
     */
    private function keyed(Entry $entry): array
    {
        $rows = DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->get(['id', 'parent_id', 'item_type', 'item_id', 'color_id', 'qty', 'lost_qty', 'note',
                'is_extra', 'is_alternate', 'is_counterpart', 'match_id']);

        $children = [];

        foreach ($rows as $row) {
            $children[$row->parent_id ?? 0][] = $row;
        }

        $keys = [];

        $walk = function (?int $parentId, string $prefix) use (&$walk, &$keys, $children): void {
            $siblings = $children[$parentId ?? 0] ?? [];

            $seen = [];

            usort($siblings, fn ($a, $b) => [$a->item_type, $a->item_id, $a->color_id, $a->is_extra,
                $a->is_alternate, $a->is_counterpart, $a->match_id, $a->id]
                <=> [$b->item_type, $b->item_id, $b->color_id, $b->is_extra,
                    $b->is_alternate, $b->is_counterpart, $b->match_id, $b->id]);

            foreach ($siblings as $row) {
                $step = implode(':', [
                    $row->item_type, $row->item_id, $row->color_id,
                    (int) $row->is_extra, (int) $row->is_alternate, (int) $row->is_counterpart, $row->match_id,
                ]);

                $ordinal = $seen[$step] = ($seen[$step] ?? -1) + 1;
                $key = $prefix.'/'.$step.'#'.$ordinal;

                $keys[$key] = $row;

                $walk((int) $row->id, $key);
            }
        };

        $walk(null, '');

        return $keys;
    }

    /**
     * @param  array<string, object>  $before
     * @param  array<string, object>  $after
     */
    private function quantitiesDiffer(array $before, array $after): bool
    {
        foreach ($after as $key => $row) {
            if (isset($before[$key]) && (int) $before[$key]->qty !== (int) $row->qty) {
                return true;
            }
        }

        return false;
    }
}
