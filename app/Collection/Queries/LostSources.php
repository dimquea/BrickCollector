<?php

namespace App\Collection\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Откуда вещь пропала — то, что пишется в примечание к списку желаемого.
 *
 * Заказывая деталь взамен потерянной, хочется видеть не только её номер, но и
 * чему её недостаёт: одна и та же деталь могла пропасть из трёх наборов, и в
 * магазине это разница между «взять одну» и «взять три».
 *
 * Партия россыпью в источники не идёт: её номер — это номер самой детали, и
 * примечание «2431» у детали 2431 не сообщает ничего. У сборки номера нет
 * вовсе, поэтому её называют именем, которое ей дали.
 *
 * Потеря считается по тем же строкам, что и везде: в зачёте и парным. Парная
 * деталь — тот же кирпичик, описанный дважды, и в количество она не идёт, но
 * пропасть может.
 */
class LostSources
{
    /**
     * Ключ — «артикул/цвет», как в списке деталей.
     *
     * @return Collection<string, string>
     */
    public function parts(): Collection
    {
        return $this->rows('P')
            ->groupBy(fn (object $row) => $row->item_id.'/'.(int) $row->color_id)
            ->map(fn (Collection $rows) => $this->listed($rows));
    }

    /** @return Collection<string, string> */
    public function minifigures(): Collection
    {
        return $this->rows('M')
            ->groupBy(fn (object $row) => (string) $row->item_id)
            ->map(fn (Collection $rows) => $this->listed($rows));
    }

    /** @return Collection<int, object> */
    private function rows(string $type): Collection
    {
        return DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->where('ci.item_type', $type)
            ->where('ci.lost_qty', '>', 0)
            ->whereRaw('(ci.counts = 1 OR ci.is_counterpart = 1)')
            ->where(fn ($where) => $where->whereNull('e.item_type')->orWhere('e.item_type', '!=', 'P'))
            ->orderByRaw('COALESCE(e.item_id, e.name)')
            ->get(['ci.item_id', 'ci.color_id', DB::raw('COALESCE(e.item_id, e.name) as source')]);
    }

    /** @param Collection<int, object> $rows */
    private function listed(Collection $rows): string
    {
        return $rows->pluck('source')->filter()->unique()->implode(', ');
    }
}
