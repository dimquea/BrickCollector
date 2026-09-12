<?php

namespace App\Collection\Queries;

use App\Http\Controllers\SetsController;
use Illuminate\Support\Facades\DB;

/**
 * Сводка по коллекции.
 *
 * Числа здесь считаются по тем же правилам, что и в разделах, иначе аналитика
 * противоречила бы спискам: строки, не идущие в зачёт состава (запасные,
 * альтернативы, counterpart), исключены везде одинаково — это `counts = 1`.
 *
 * Считаем по требованию, без кеша: вся коллекция — это десятки тысяч строк в
 * SQLite на локальном диске, а таблица, которую надо не забыть пересчитать,
 * стоила бы дороже самого счёта.
 */
class Analytics
{
    /**
     * Сколько чего в коллекции.
     *
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        return [
            'sets' => $this->sets(),
            'minifigures' => $this->minifigures(),
            'parts' => $this->parts(),
        ];
    }

    /** @return array<string, int> */
    private function sets(): array
    {
        $row = DB::table('collection_entries')
            ->whereIn('item_type', SetsController::TYPES)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT item_id) as unique_items')
            ->selectRaw('COALESCE(SUM(flag_incomplete), 0) as incomplete')
            ->first();

        return [
            'total' => (int) $row->total,
            'unique' => (int) $row->unique_items,
            'incomplete' => (int) $row->incomplete,
        ];
    }

    /**
     * Фигурка «в наборе» — это любая, кроме той, которой владеют отдельно.
     * Минифигурки набора лежат корневыми строками его дерева, поэтому признаком
     * служит не наличие родителя, а тип самой записи.
     *
     * @return array<string, int>
     */
    private function minifigures(): array
    {
        $loose = "ci.parent_item_type IS NULL AND e.item_type = 'M'";

        $row = DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->where('ci.item_type', 'M')
            ->where('ci.counts', 1)
            ->selectRaw('COALESCE(SUM(ci.qty), 0) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN NOT ({$loose}) THEN ci.qty END), 0) as in_sets")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$loose} THEN ci.qty END), 0) as loose")
            ->selectRaw('COUNT(DISTINCT ci.item_id) as unique_items')
            ->first();

        // Некомплектная фигурка — та, внутри которой чего-то не хватает. Её
        // детали лежат прямо под ней, поэтому достаточно посмотреть на детей.
        $incomplete = DB::table('collection_items as ci')
            ->where('ci.item_type', 'M')
            ->where('ci.counts', 1)
            ->whereExists(fn ($sub) => $sub
                ->from('collection_items as part')
                ->whereColumn('part.parent_id', 'ci.id')
                ->where('part.counts', 1)
                ->where('part.lost_qty', '>', 0)
                ->selectRaw('1'))
            ->count();

        return [
            'total' => (int) $row->total,
            'in_sets' => (int) $row->in_sets,
            'loose' => (int) $row->loose,
            'unique' => (int) $row->unique_items,
            'incomplete' => $incomplete,
        ];
    }

    /**
     * «В наборах» здесь — всё, что лежит внутри чего-то купленного, включая
     * детали его минифигурок: раздел деталей различает эти два места, а сводке
     * важна только граница между «в составе» и «россыпью».
     *
     * @return array<string, int>
     */
    private function parts(): array
    {
        $loose = "ci.parent_item_type IS NULL AND e.item_type = 'P'";
        // Сборка — запись без типа: её детали не куплены в составе чего-то и
        // не лежат россыпью, поэтому у них своя колонка. Иначе они молча
        // приписывались бы к наборам.
        $assembled = 'ci.parent_item_type IS NULL AND e.item_type IS NULL';

        $row = DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->where('ci.item_type', 'P')
            ->where('ci.counts', 1)
            ->selectRaw('COALESCE(SUM(ci.qty), 0) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN NOT ({$loose}) AND NOT ({$assembled}) THEN ci.qty END), 0) as in_sets")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$loose} THEN ci.qty END), 0) as loose")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$assembled} THEN ci.qty END), 0) as assemblies")
            ->selectRaw('COUNT(DISTINCT ci.item_id || \'/\' || ci.color_id) as unique_items')
            ->selectRaw('COALESCE(SUM(ci.lost_qty), 0) as lost')
            ->first();

        return [
            'total' => (int) $row->total,
            'in_sets' => (int) $row->in_sets,
            'loose' => (int) $row->loose,
            'assemblies' => (int) $row->assemblies,
            'unique' => (int) $row->unique_items,
            'lost' => (int) $row->lost,
        ];
    }

    /**
     * Деньги.
     *
     * Считаем только по тем экземплярам, у которых цена указана: средняя по
     * всей коллекции, где половина без цены, — это не средняя цена покупки, а
     * ничто.
     *
     * @return array<string, mixed>
     */
    public function finance(): array
    {
        $row = DB::table('collection_entries')
            ->whereNotNull('price')
            ->selectRaw('COALESCE(SUM(price), 0) as total')
            ->selectRaw('COUNT(*) as priced')
            ->first();

        $priciest = DB::table('collection_entries as e')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->whereNotNull('e.price')
            ->orderByDesc('e.price')
            ->first([
                'e.id',
                'e.item_id',
                'e.price',
                DB::raw('COALESCE(i.name, e.name, e.item_id) as name'),
            ]);

        return [
            'total' => (int) $row->total,
            'priced' => (int) $row->priced,
            'average' => $row->priced > 0 ? (int) round($row->total / $row->priced) : null,
            'priciest' => $priciest === null ? null : [
                'entry_id' => (int) $priciest->id,
                'item_id' => $priciest->item_id,
                'name' => $priciest->name,
                'price' => (int) $priciest->price,
            ],
        ];
    }

    /**
     * По темам — корневым, а не листовым.
     *
     * «Star Wars / Ultimate Collector Series / Star Wars Other» отдельной
     * строкой не сообщает ничего: таких строк будет почти столько же, сколько
     * наборов. Фильтры в разделах понимают тему как поддерево, поэтому клик по
     * числу открывает ровно то, что в нём посчитано.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byTheme(): array
    {
        // Корень пути: всё до первого разделителя. Дешевле, чем подниматься по
        // дереву тем, и путь здесь — готовая строка.
        $root = "CASE WHEN instr(t.path, ' / ') > 0
            THEN substr(t.path, 1, instr(t.path, ' / ') - 1) ELSE t.path END";

        $sets = $this->ownedSets()
            ->join('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->selectRaw("{$root} as label")
            ->selectRaw('COUNT(*) as sets')
            ->selectRaw('COALESCE(SUM(e.price), 0) as price')
            ->groupBy(DB::raw($root))
            ->get();

        $figures = $this->ownedFigures()
            ->join('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->selectRaw("{$root} as label")
            // Артикулы, а не экземпляры: по числу кликают, и раздел минифигурок
            // показывает одну карточку на артикул. Обещать 53 и открыть 50 —
            // хуже, чем не делать ссылку вовсе.
            ->selectRaw('COUNT(DISTINCT ci.item_id) as figures')
            ->groupBy(DB::raw($root))
            ->pluck('figures', 'label');

        $ids = DB::table('bl_themes')->where('depth', 0)->pluck('id', 'path');

        $rows = $sets->map(fn ($row) => [
            'key' => $ids[$row->label] ?? null,
            'label' => (string) $row->label,
            'sets' => (int) $row->sets,
            'figures' => (int) ($figures[$row->label] ?? 0),
            'price' => (int) $row->price,
        ])->all();

        usort($rows, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * По годам выпуска набора.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byYear(): array
    {
        $sets = $this->ownedSets()
            ->whereNotNull('i.year')
            ->selectRaw('i.year as label')
            ->selectRaw('COUNT(*) as sets')
            ->selectRaw('COALESCE(SUM(e.price), 0) as price')
            ->groupBy('i.year')
            ->get();

        $figures = $this->ownedFigures()
            ->whereNotNull('i.year')
            ->selectRaw('i.year as label')
            ->selectRaw('COUNT(DISTINCT ci.item_id) as figures')
            ->groupBy('i.year')
            ->pluck('figures', 'label');

        $rows = $sets->map(fn ($row) => [
            'key' => (int) $row->label,
            'label' => (string) $row->label,
            'sets' => (int) $row->sets,
            'figures' => (int) ($figures[$row->label] ?? 0),
            'price' => (int) $row->price,
        ])->all();

        usort($rows, fn (array $a, array $b) => $b['key'] <=> $a['key']);

        return $rows;
    }

    /** Экземпляры раздела «Наборы», со сведениями из справочника. */
    private function ownedSets(): \Illuminate\Database\Query\Builder
    {
        return DB::table('collection_entries as e')
            ->join('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->whereIn('e.item_type', SetsController::TYPES);
    }

    /** Строки минифигурок, идущие в зачёт, со сведениями из справочника. */
    private function ownedFigures(): \Illuminate\Database\Query\Builder
    {
        return DB::table('collection_items as ci')
            ->join('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'ci.item_type')
                ->on('i.id', '=', 'ci.item_id'))
            ->where('ci.item_type', 'M')
            ->where('ci.counts', 1);
    }
}
