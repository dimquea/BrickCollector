<?php

namespace App\Catalog\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Чем ещё называют предмет, кроме артикула BrickLink.
 *
 * Код элемента LEGO — семизначное число из инструкции и с полки Pick a Brick,
 * 111 тысяч штук. Называет он не деталь вообще, а деталь в цвете, и это важно:
 * 4243814 — это Plate 4 x 4 красная, а 4243815 — она же синяя.
 *
 * Альтернативный артикул BrickLink — второе имя того же предмета, ещё 17
 * тысяч. Цвета не знает.
 *
 * Ищется точным совпадением, без догадок о том, похожа ли строка на номер:
 * обычное слово просто не найдётся, а обе таблицы проиндексированы, и стоит
 * это доли миллисекунды. В поисковый индекс справочника номера намеренно не
 * кладутся — почему, написано в SearchItems.
 */
final class ItemIdentifiers
{
    /**
     * @return Collection<int, object> type, id и color_id — у кода цвет есть,
     *                                 у второго имени он null
     */
    public static function resolve(string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $codes = DB::table('bl_element_codes')
            ->where('code', $term)
            ->select(['item_type as type', 'item_id as id', 'color_id']);

        $alternates = DB::table('bl_item_alt_ids')
            ->where('alt_id', $term)
            ->selectRaw('type, id, null as color_id');

        return $codes->union($alternates)->get();
    }

    /**
     * Добавляет к условию поиска точные совпадения по идентификатору.
     *
     * Колонки называет вызывающий: в справочнике это предмет, в разделах
     * коллекции — её строки, и зовутся они везде по-своему. Цвет
     * проверяется, когда код его назвал и колонка для него есть: раздел
     * деталей показывает строку на пару «артикул + цвет», и код попадает
     * ровно в одну.
     *
     * @param  Collection<int, object>  $identifiers
     */
    public static function match(
        Builder|\Illuminate\Database\Eloquent\Builder $where,
        Collection $identifiers,
        string $typeColumn,
        string $idColumn,
        ?string $colorColumn = null,
    ): void {
        foreach ($identifiers as $row) {
            $where->orWhere(function ($match) use ($row, $typeColumn, $idColumn, $colorColumn) {
                $match->where($typeColumn, $row->type)->where($idColumn, $row->id);

                if ($colorColumn !== null && $row->color_id !== null) {
                    $match->where($colorColumn, $row->color_id);
                }
            });
        }
    }
}
