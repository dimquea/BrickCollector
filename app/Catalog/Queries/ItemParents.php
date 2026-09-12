<?php

namespace App\Catalog\Queries;

use App\Catalog\Models\Item;
use App\Catalog\Models\ItemType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * What an item is part of — the inventory read backwards.
 *
 * The index on (child_type, child_id, color_id) makes the lookup itself cheap,
 * and for a minifigure or an ordinary part that is the whole story: a dozen
 * parents, answered in a millisecond. The heavy tail is a common brick — 4073
 * is in 34 thousand inventories — and there the cost is in what is read, not
 * in what is found.
 *
 * So rows come out in the index's own order, by the parent's item number, and
 * only the fifty on the page have their names looked up. Ordering by name or
 * by year would mean joining all 34 thousand parents before the first row
 * could be shown: two seconds, for an option that would look like any other.
 *
 * For a part the colour narrows it further, and that is free — the colour is
 * the third column of the index.
 */
class ItemParents
{
    public const PER_PAGE = 50;

    /**
     * How many distinct parents of each kind, in the order kinds are listed.
     *
     * @return array<string, int> kind code => count, empty kinds dropped
     */
    public function kinds(string $type, string $id, ?int $colorId = null): array
    {
        $counts = $this->base($type, $id, $colorId)
            ->groupBy('parent_type')
            ->pluck(DB::raw('COUNT(DISTINCT parent_id)'), 'parent_type');

        $ordered = [];

        foreach (ItemType::BROWSABLE as $code) {
            if (($counts[$code] ?? 0) > 0) {
                $ordered[$code] = (int) $counts[$code];
            }
        }

        return $ordered;
    }

    /** One page of parents of a single kind, oldest catalogue number first. */
    public function page(
        string $type,
        string $id,
        string $kind,
        ?int $colorId,
        int $total,
        int $page,
    ): LengthAwarePaginator {
        $rows = $this->base($type, $id, $colorId)
            ->where('parent_type', $kind)
            ->groupBy('parent_id')
            ->orderBy('parent_id')
            ->limit(self::PER_PAGE)
            ->offset((max(1, $page) - 1) * self::PER_PAGE)
            ->get(['parent_id', DB::raw('SUM(qty) as qty')]);

        // Names for the fifty on the page, not for every parent there is.
        $items = Item::where('type', $kind)
            ->whereIn('id', $rows->pluck('parent_id'))
            ->get(['id', 'name', 'year', 'image_color_id'])
            ->keyBy('id');

        return new LengthAwarePaginator(
            $rows->map(fn ($row) => [
                'type' => $kind,
                'id' => $row->parent_id,
                'name' => $items[$row->parent_id]->name ?? $row->parent_id,
                'year' => $items[$row->parent_id]->year ?? null,
                'image_color_id' => (int) ($items[$row->parent_id]->image_color_id ?? 0),
                'qty' => (int) $row->qty,
            ])->all(),
            $total,
            self::PER_PAGE,
            $page,
            ['pageName' => 'in_page'],
        );
    }

    /**
     * Colours this part is used in anywhere, for the filter.
     *
     * From the inventory rather than the palette: a colour that returns
     * nothing has no business being offered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function colours(string $id): array
    {
        $ids = DB::table('bl_inventory')
            ->where('child_type', 'P')
            ->where('child_id', $id)
            ->distinct()
            ->pluck('color_id');

        return DB::table('bl_colors')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'rgb'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function base(string $type, string $id, ?int $colorId): \Illuminate\Database\Query\Builder
    {
        return DB::table('bl_inventory')
            ->where('child_type', $type)
            ->where('child_id', $id)
            ->when($colorId !== null, fn ($query) => $query->where('color_id', $colorId));
    }
}
