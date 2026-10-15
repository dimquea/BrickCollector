<?php

namespace App\Collection\Actions;

use App\Collection\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Записывает опись справочника строками экземпляра.
 *
 * Отдельным классом, потому что писателей стало двое: добавление в коллекцию и
 * обновление описей. Правила здесь непростые — количества перемножаются вниз,
 * непосчитанная позиция уводит за собой всё поддерево, — и расходиться им в
 * двух местах нельзя: один экземпляр набора считался бы иначе, чем соседний.
 */
class WriteContents
{
    /**
     * Корневая строка самого предмета — для всего, что не набор.
     *
     * У набора такой строки нет: набор и есть экземпляр, его позиции ложатся
     * в корень. Фигурка, деталь, аксессуар — вещи сами по себе, и строка о них
     * нужна, иначе нечего было бы считать.
     *
     * @return int идентификатор строки
     */
    public function root(Entry $entry, string $type, string $id, int $colorId, int $qty): int
    {
        return $this->row($entry, [
            'type' => $type,
            'id' => $id,
            'color_id' => $colorId,
            'qty' => $qty,
            'is_extra' => false,
            'is_alternate' => false,
            'is_counterpart' => false,
            'match_id' => 0,
        ], null, null);
    }

    /**
     * Пишет один уровень дерева и спускается ниже.
     *
     * Quantities are multiplied down the tree. A box holding 36 packets of one
     * part means 36 of that part in the collection, and part counting is a
     * single SUM(qty) over these rows — it does not walk back up looking for
     * ancestors to multiply by.
     *
     * A lot that does not count takes its whole subtree with it. The eleven
     * alternates of a random packet are not in the box, and neither are the
     * minifigures inside them.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public function store(
        Entry $entry,
        array $nodes,
        ?int $parentId,
        ?string $parentType,
        int $multiplier,
        bool $parentCounts,
    ): void {
        foreach ($nodes as $node) {
            $counts = $parentCounts
                && ! $node['is_extra']
                && ! $node['is_alternate']
                && ! $node['is_counterpart'];

            $id = $this->row($entry, $node, $parentId, $parentType, $multiplier, $counts);

            if ($node['children']) {
                $this->store($entry, $node['children'], $id, $node['type'], $multiplier * $node['qty'], $counts);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function row(
        Entry $entry,
        array $node,
        ?int $parentId,
        ?string $parentType,
        int $multiplier = 1,
        bool $counts = true,
    ): int {
        // Через построитель запросов, а не через модель: строк в большом
        // наборе полторы тысячи, а при обновлении описей — десятки тысяч за
        // раз, и создание модели со всеми её событиями на каждую стоит дороже
        // самой записи. Полей у строки немного, и все они здесь.
        return DB::table('collection_items')->insertGetId([
            'entry_id' => $entry->id,
            'parent_id' => $parentId,
            'parent_item_type' => $parentType,
            'item_type' => $node['type'],
            'item_id' => $node['id'],
            'color_id' => $node['color_id'],
            'qty' => $node['qty'] * $multiplier,
            'lost_qty' => 0,
            'is_extra' => $node['is_extra'],
            'is_alternate' => $node['is_alternate'],
            'is_counterpart' => $node['is_counterpart'],
            'match_id' => $node['match_id'],
            'counts' => $counts,
        ]);
    }
}
