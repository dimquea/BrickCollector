<?php

namespace App\Collection\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where one minifigure is, across the collection.
 */
class MinifigurePlaces
{
    public function __construct(private readonly string $itemId) {}

    /** Owned copies that contain this figure, however deep it sits. */
    public function inEntries(): Collection
    {
        return $this->builtIn()
            ->groupBy('e.id')
            ->orderBy('name')
            ->get([
                'e.id as entry_id',
                'e.item_type as type',
                'e.item_id',
                DB::raw('COALESCE(i.name, e.item_id) as name'),
                DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),
                DB::raw('SUM(ci.qty) as qty'),
                DB::raw('SUM(ci.lost_qty) as lost'),
            ]);
    }

    /** Owned copies where this figure is marked missing. */
    public function missingIn(): Collection
    {
        return $this->builtIn()
            ->where('ci.lost_qty', '>', 0)
            ->groupBy('e.id')
            ->orderBy('name')
            ->get([
                'e.id as entry_id',
                'e.item_type as type',
                'e.item_id',
                DB::raw('COALESCE(i.name, e.item_id) as name'),
                DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),
                DB::raw('SUM(ci.qty) as qty'),
                DB::raw('SUM(ci.lost_qty) as lost'),
            ]);
    }

    /**
     * What the figure is made of, as recorded in the collection.
     *
     * Taken from any one copy: every copy of the same figure was expanded from
     * the same catalog inventory, so they agree. Quantities come from that one
     * copy, not the sum across all of them — this answers "what is it made of",
     * not "how many of these do I hold".
     */
    public function parts(): Collection
    {
        $figure = DB::table('collection_items')
            ->where('item_type', 'M')
            ->where('item_id', $this->itemId)
            ->where('counts', 1)
            ->orderBy('id')
            ->first(['id', 'qty']);

        if ($figure === null) {
            return collect();
        }

        $perFigure = max(1, (int) $figure->qty);

        return DB::table('collection_items as ci')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'ci.item_type')
                ->on('i.id', '=', 'ci.item_id'))
            ->leftJoin('bl_colors as c', 'c.id', '=', 'ci.color_id')
            ->where('ci.parent_id', $figure->id)
            ->orderBy('i.name')
            ->get([
                'ci.item_id',
                'ci.color_id',
                'ci.qty',
                'ci.is_extra',
                'ci.is_alternate',
                'ci.is_counterpart',
                DB::raw('COALESCE(i.name, ci.item_id) as name'),
                'c.name as color_name',
                'c.rgb as color_rgb',
            ])
            ->map(function (object $row) use ($perFigure) {
                // The copy this was read from may itself have come in a
                // quantity — a boxed series holds thirty-six of a packet — and
                // its rows carry that multiplier. Divided back out, because the
                // question is what one figure is made of.
                $row->qty = intdiv((int) $row->qty, $perFigure);

                return $row;
            });
    }

    private function builtIn(): \Illuminate\Database\Query\Builder
    {
        return DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->where('ci.item_type', 'M')
            ->where('ci.item_id', $this->itemId)
            // Built into something, as opposed to owned on its own. A set's
            // minifigures are root rows of that set, so this cannot test for
            // the presence of a parent.
            ->whereNot(fn ($where) => $where
                ->whereNull('ci.parent_item_type')
                ->where('e.item_type', 'M'));
    }
}
