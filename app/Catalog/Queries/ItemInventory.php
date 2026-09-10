<?php

namespace App\Catalog\Queries;

use App\Catalog\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Expands what an item is made of.
 *
 * A set contains parts, but also minifigures and whole subsets — the boxed
 * series, the days of an advent calendar, a set inside a promotional pack —
 * each with contents of their own. Those are expanded; assembled parts are
 * not. BrickLink lists a torso as a pair of arms, but physically a set
 * contains one torso, and expanding it would put arms nobody owns into the
 * part counts.
 *
 * Loaded a sibling group at a time rather than node by node: a set has
 * hundreds of lots, and asking about each one separately would be hundreds of
 * queries. The Millennium Falcon's 765 lots take two.
 */
class ItemInventory
{
    /** The catalog reaches six levels; the limit is slack for a bad release. */
    private const MAX_DEPTH = 8;

    /** Types whose own inventory is worth expanding. */
    private const EXPANDABLE = ['M', 'S'];

    /**
     * @return array<int, array<string, mixed>> nested by "children"
     */
    public function tree(string $type, string $id): array
    {
        $roots = $this->lots($type, $id);

        $this->expand($roots, [$type.'/'.$id], 1);

        return $roots;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  string[]  $path  keys already visited, to stop a cycle
     */
    private function expand(array &$nodes, array $path, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        $wanted = [];

        foreach ($nodes as $node) {
            $key = $node['type'].'/'.$node['id'];

            if (in_array($node['type'], self::EXPANDABLE, true) && ! in_array($key, $path, true)) {
                $wanted[$key] = [$node['type'], $node['id']];
            }
        }

        if (! $wanted) {
            return;
        }

        $children = $this->lotsFor(array_values($wanted));

        foreach ($nodes as &$node) {
            $key = $node['type'].'/'.$node['id'];

            if (! isset($children[$key])) {
                continue;
            }

            $node['children'] = $children[$key];
            $this->expand($node['children'], [...$path, $key], $depth + 1);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function lots(string $type, string $id): array
    {
        return $this->lotsFor([[$type, $id]])[$type.'/'.$id] ?? [];
    }

    /**
     * One query for every parent at a level.
     *
     * @param  array<int, array{0: string, 1: string}>  $parents
     * @return array<string, array<int, array<string, mixed>>> keyed by "type/id"
     */
    private function lotsFor(array $parents): array
    {
        $rows = DB::table('bl_inventory as inv')
            ->leftJoin('bl_items as it', fn ($join) => $join
                ->on('it.type', '=', 'inv.child_type')
                ->on('it.id', '=', 'inv.child_id'))
            ->leftJoin('bl_colors as c', 'c.id', '=', 'inv.color_id')
            ->select([
                'inv.parent_type', 'inv.parent_id',
                'inv.child_type', 'inv.child_id', 'inv.color_id', 'inv.qty',
                'inv.is_extra', 'inv.is_alternate', 'inv.match_id', 'inv.is_counterpart',
                'it.name', 'it.image_color_id', 'it.has_inventory',
                'c.name as color_name', 'c.rgb as color_rgb',
            ])
            ->where(function ($query) use ($parents) {
                foreach ($parents as [$type, $id]) {
                    $query->orWhere(fn ($w) => $w->where('inv.parent_type', $type)->where('inv.parent_id', $id));
                }
            })
            ->orderBy('inv.child_type')
            ->orderBy('inv.child_id')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->parent_type.'/'.$row->parent_id][] = [
                'type' => $row->child_type,
                'id' => $row->child_id,
                // A missing name means the catalog references something it did
                // not ship; show the id rather than an empty card.
                'name' => $row->name ?? $row->child_id,
                'qty' => (int) $row->qty,
                'color_id' => (int) $row->color_id,
                'color_name' => $row->color_name,
                'color_rgb' => $row->color_rgb,
                'image_color_id' => (int) ($row->image_color_id ?? 0),
                'has_inventory' => (bool) $row->has_inventory,
                'is_extra' => (bool) $row->is_extra,
                'is_alternate' => (bool) $row->is_alternate,
                'is_counterpart' => (bool) $row->is_counterpart,
                'match_id' => (int) $row->match_id,
                'children' => [],
            ];
        }

        return $grouped;
    }

    /**
     * Totals for the summary line.
     *
     * Spares, alternates and counterparts are attached to the item but do not
     * count: an alternate is a second way to build the same lot, and counting
     * both would inflate every set that has one.
     *
     * The exclusion reaches the whole subtree. A "random packet" set lists all
     * twelve possibilities as alternates of one another; counting the
     * minifigure inside each of them turns a box of 36 packets into 432
     * minifigures.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array{parts: int, lots: int, minifigures: int, subsets: int, extras: int}
     */
    public function summarise(array $nodes): array
    {
        $totals = ['parts' => 0, 'lots' => 0, 'minifigures' => 0, 'subsets' => 0, 'extras' => 0];

        $walk = function (array $nodes, bool $parentCounts, int $multiplier) use (&$walk, &$totals): void {
            foreach ($nodes as $node) {
                $qty = $node['qty'] * $multiplier;

                if ($node['is_extra'] && $parentCounts) {
                    $totals['extras'] += $qty;
                }

                $counts = $parentCounts
                    && ! $node['is_extra']
                    && ! $node['is_alternate']
                    && ! $node['is_counterpart'];

                if ($counts) {
                    match ($node['type']) {
                        'P' => [$totals['parts'] += $qty, $totals['lots']++],
                        'M' => $totals['minifigures'] += $qty,
                        'S' => $totals['subsets'] += $qty,
                        default => null,
                    };
                }

                $walk($node['children'], $counts, $qty);
            }
        };

        $walk($nodes, true, 1);

        return $totals;
    }
}
