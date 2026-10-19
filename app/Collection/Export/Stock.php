<?php

namespace App\Collection\Export;

use Illuminate\Support\Facades\DB;

/**
 * Склад: то, что можно выставить на продажу, — по экземплярам, а не итогами.
 *
 * Опись отвечает «сколько у меня таких», склад — «вот эти лоты»: у каждой
 * партии свои цена, состояние и заметка, и сложить две партии одной детали
 * значило бы потерять всё, чем они различаются. Поэтому строка здесь — это
 * экземпляр коллекции: свободная партия, отдельная фигурка, набор.
 *
 * Деталь внутри набора на склад не идёт: своей цены и своего состояния у неё
 * нет, а продают её вместе с коробкой.
 *
 * Цена у нас хранится на всю партию, а склад просит цену за штуку, поэтому она
 * делится на нынешнее количество. Это осознанное допущение: партия, часть
 * которой ушла в сборку или в набор, свою цену не пересчитывает, и цена за
 * штуку у неё выходит выше, чем была при покупке. Приложение — менеджер
 * коллекции, а не учётная система, и следить за этим — дело владельца.
 */
final class Stock
{
    /**
     * @param  iterable<int, int>  $entryIds
     * @return array<int, array{entry_id: int, type: string, id: string, color: int, qty: int, price: float,
     *     used: bool, subcondition: ?string, remarks: ?string, name: ?string, color_name: ?string, category: ?int}>
     */
    public static function lots(iterable $entryIds): array
    {
        $ids = collect($entryIds)->map(fn ($id) => (int) $id)->unique()->values();
        $lots = [];

        foreach ($ids->chunk(500) as $chunk) {
            $rows = DB::table('collection_entries as e')
                // У набора корневой строки нет: набор и есть экземпляр, его
                // позиции лежат в корне. У всего прочего она одна — сам предмет.
                ->leftJoin('collection_items as root', fn ($join) => $join
                    ->on('root.entry_id', '=', 'e.id')
                    ->whereNull('root.parent_id')
                    ->where('e.item_type', '!=', 'S'))
                ->leftJoin('bl_items as i', fn ($join) => $join
                    ->on('i.type', '=', 'e.item_type')
                    ->on('i.id', '=', 'e.item_id'))
                ->leftJoin('bl_colors as c', 'c.id', '=', DB::raw('COALESCE(root.color_id, e.color_id, 0)'))
                ->whereIn('e.id', $chunk->all())
                ->whereNotNull('e.item_type')
                ->orderBy('e.item_type')
                ->orderBy('e.item_id')
                ->orderBy('e.id')
                ->get([
                    'e.id', 'e.item_type', 'e.item_id', 'e.color_id', 'e.price', 'e.note', 'e.is_used',
                    'e.flag_incomplete', 'root.qty', 'root.lost_qty', 'root.color_id as root_color',
                    'i.name', 'i.category_id', 'c.name as color_name',
                ]);

            foreach ($rows as $row) {
                $set = $row->item_type === 'S';
                $held = $set ? 1 : (int) $row->qty;
                // Потерянное не продашь: на склад идёт то, что на руках.
                $qty = $set ? 1 : $held - (int) $row->lost_qty;

                if ($qty < 1) {
                    continue;
                }

                $note = trim((string) $row->note);

                $lots[] = [
                    'entry_id' => (int) $row->id,
                    'type' => $row->item_type,
                    'id' => $row->item_id,
                    'color' => (int) ($row->root_color ?? $row->color_id ?? 0),
                    'qty' => $qty,
                    'price' => $row->price === null ? 0.0 : $row->price / 100 / max(1, $held),
                    'used' => (bool) $row->is_used,
                    // Полнота — только у набора: у детали и фигурки её нет.
                    'subcondition' => $set ? ($row->flag_incomplete ? 'I' : 'C') : null,
                    'remarks' => $note === '' ? null : $note,
                    'name' => $row->name,
                    'color_name' => $row->color_name,
                    'category' => $row->category_id === null ? null : (int) $row->category_id,
                ];
            }
        }

        return $lots;
    }
}
