<?php

namespace App\Collection\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where one part is, across the collection.
 *
 * Answers the questions the part page asks in its tabs: which owned copies
 * hold it, which minifigures it is built into, and where it has gone missing.
 */
class PartPlaces
{
    public function __construct(
        private readonly string $itemId,
        private readonly int $colorId,
    ) {}

    /**
     * Owned copies holding this part directly — a set, or the loose pile.
     */
    public function inEntries(): Collection
    {
        return $this->rows()
            // Grouped, not chained: an orWhere at the top level escapes the
            // constraints added by rows() and matches every part in the
            // collection. It read as 71 of a brick there were two of.
            ->where(fn ($where) => $where
                ->whereNull('ci.parent_id')
                ->orWhere('ci.parent_item_type', 'S'))
            ->get()
            ->groupBy('entry_id')
            ->map(fn ($rows) => $this->summarise($rows))
            ->values();
    }

    /**
     * Minifigures built with this part.
     *
     * Grouped by the figure rather than by the copy it belongs to: "which
     * figures use this piece" is a catalog question that happens to be
     * answered from the collection.
     */
    public function inMinifigures(): Collection
    {
        return DB::table('collection_items as ci')
            ->join('collection_items as fig', 'fig.id', '=', 'ci.parent_id')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'fig.item_type')
                ->on('i.id', '=', 'fig.item_id'))
            ->where('ci.item_type', 'P')
            ->where('ci.item_id', $this->itemId)
            ->where('ci.color_id', $this->colorId)
            ->where('ci.parent_item_type', 'M')
            ->groupBy('fig.item_id')
            ->orderBy('i.name')
            ->get([
                'fig.item_id',
                DB::raw('COALESCE(i.name, fig.item_id) as name'),
                DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),
                DB::raw('SUM(ci.qty) as qty'),
                DB::raw('SUM(ci.lost_qty) as lost'),
                DB::raw('COUNT(DISTINCT ci.entry_id) as entries'),
            ]);
    }

    /** Owned copies where this part is marked missing. */
    public function missingIn(): Collection
    {
        return $this->rows()
            ->where('ci.lost_qty', '>', 0)
            ->get()
            ->groupBy('entry_id')
            ->map(fn ($rows) => $this->summarise($rows))
            ->values();
    }

    private function rows(): \Illuminate\Database\Query\Builder
    {
        return DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->where('ci.item_type', 'P')
            ->where('ci.item_id', $this->itemId)
            ->where('ci.color_id', $this->colorId)
            ->select([
                'ci.entry_id',
                'ci.qty',
                'ci.lost_qty',
                'e.item_type as entry_type',
                'e.item_id as entry_item_id',
                'e.name as entry_name',
                DB::raw('COALESCE(i.name, e.item_id) as name'),
                DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),
            ]);
    }

    /** @param \Illuminate\Support\Collection<int, object> $rows */
    private function summarise(Collection $rows): array
    {
        $first = $rows->first();

        return [
            'entry_id' => $first->entry_id,
            'type' => $first->entry_type,
            'item_id' => $first->entry_item_id,
            'name' => $first->entry_name ?? $first->name,
            'image_color_id' => (int) $first->image_color_id,
            'qty' => (int) $rows->sum('qty'),
            'lost' => (int) $rows->sum('lost_qty'),
        ];
    }
}
