<?php

namespace App\Collection\Queries;

use App\Catalog\Queries\ItemInventory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Детали внутри составных деталей — только для справки.
 *
 * Составная деталь — торс с руками и кистями, рельс со шпалами — лежит в
 * коллекции одной строкой и не раскладывается: это один кусок пластмассы,
 * описанный дважды, и храни мы и торс, и его части, одна деталь считалась бы
 * два раза. Поэтому кисти внутри торсов не видны в итогах раздела деталей —
 * и не должны быть.
 *
 * Но вопрос «есть ли у меня такая кисть в таком цвете» законный, и ответ на
 * него есть: какие составные детали лежат в коллекции, известно, а их состав
 * знает справочник. Отсюда и считается — на лету, ничего не записывая и ни во
 * что не складываясь.
 *
 * Глубина — один уровень: составная внутри составной в справочнике почти не
 * встречается. Потерянная составная деталь не в счёт — её кистей тоже нет на
 * руках.
 */
final class Composites
{
    /**
     * Сколько каждой детали в каждом цвете сидит внутри составных, лежащих в
     * коллекции. Колонки названы как у строк коллекции, чтобы раздел деталей
     * мог подставить этот подзапрос на место своих строк.
     */
    public static function inside(): Builder
    {
        // Унарный плюс у child_type не даёт SQLite взять индекс по ребёнку: с
        // ним он перебирал весь справочный состав — миллион строк — и сверял
        // каждую с коллекцией, девять секунд вместо долей секунды. Идти надо от
        // составных деталей коллекции по индексу родителя.
        return DB::table('collection_items as held')
            ->join('bl_inventory as inv', fn ($join) => $join
                ->on('inv.parent_id', '=', 'held.item_id')
                ->where('inv.parent_type', 'P')
                ->whereRaw("+inv.child_type = 'P'")
                ->where('inv.is_alternate', 0)
                ->where('inv.is_counterpart', 0)
                ->where('inv.is_extra', 0))
            ->where('held.item_type', 'P')
            ->where('held.counts', 1)
            ->groupBy('inv.child_id', 'inv.color_id')
            ->selectRaw("'P' as item_type, inv.child_id as item_id, inv.color_id as color_id")
            ->selectRaw('SUM((held.qty - held.lost_qty) * inv.qty) as inside');
    }

    /**
     * Составные детали коллекции, внутри которых есть эта деталь в этом цвете:
     * какая составная, в каком она цвете, сколько её на руках и сколько в
     * каждой этой детали.
     */
    public static function holding(string $itemId, int $colorId): Collection
    {
        return DB::table('collection_items as held')
            ->join('bl_inventory as inv', fn ($join) => $join
                ->on('inv.parent_id', '=', 'held.item_id')
                ->where('inv.parent_type', 'P')
                ->where('inv.child_type', 'P')
                ->where('inv.is_alternate', 0)
                ->where('inv.is_counterpart', 0)
                ->where('inv.is_extra', 0))
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.id', '=', 'held.item_id')
                ->where('i.type', 'P'))
            ->leftJoin('bl_colors as c', 'c.id', '=', 'held.color_id')
            ->where('held.item_type', 'P')
            ->where('held.counts', 1)
            ->where('inv.child_id', $itemId)
            ->where('inv.color_id', $colorId)
            ->groupBy('held.item_id', 'held.color_id', 'inv.qty')
            ->havingRaw('SUM(held.qty - held.lost_qty) > 0')
            ->orderBy('i.name')
            ->get([
                'held.item_id',
                'held.color_id',
                DB::raw('COALESCE(i.name, held.item_id) as name'),
                'c.name as color_name',
                'c.rgb as color_rgb',
                DB::raw('SUM(held.qty - held.lost_qty) as held'),
                'inv.qty as per',
                DB::raw('SUM(held.qty - held.lost_qty) * inv.qty as inside'),
            ])
            ->map(function (object $row) {
                foreach (['color_id', 'held', 'per', 'inside'] as $field) {
                    $row->{$field} = (int) $row->{$field};
                }

                return $row;
            });
    }

    /** Сколько этой детали в этом цвете сидит внутри составных — одним числом. */
    public static function count(string $itemId, int $colorId): int
    {
        return (int) self::holding($itemId, $colorId)->sum('inside');
    }

    /**
     * Из чего составная деталь собрана — по справочнику, на одну штуку.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function composition(string $itemId): array
    {
        return app(ItemInventory::class)->tree('P', $itemId);
    }
}
