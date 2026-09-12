<?php

namespace App\Collection\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Parts held across the whole collection, one row per part and colour.
 *
 * Not a list of owned copies: the same brick turns up in a dozen sets, inside
 * minifigures and loose in a drawer, and the question a person asks is "how
 * many of these do I have", not "which boxes are they in".
 *
 * Quantities were multiplied down the tree when each copy was added, so a
 * plain SUM over the rows is the real number of bricks.
 */
class PartTotals
{
    /** @var array<string, mixed> */
    private array $filters = [];

    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return $this->base()
            ->orderBy('i.name')
            ->orderBy('ci.item_id')
            ->orderBy('c.name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** One part in one colour, or null when none is held. */
    public function one(string $itemId, int $colorId): ?object
    {
        return $this->base()
            ->where('ci.item_id', $itemId)
            ->where('ci.color_id', $colorId)
            ->first();
    }

    /** The same part in every other colour that is held. */
    public function otherColours(string $itemId, int $colorId): Collection
    {
        return $this->base()
            ->where('ci.item_id', $itemId)
            ->where('ci.color_id', '!=', $colorId)
            ->orderBy('c.name')
            ->get();
    }

    /**
     * Colours actually held, for the filter.
     *
     * From the collection rather than the catalog's 216: offering a colour
     * that returns nothing reads as a loss.
     */
    public function colours(): Collection
    {
        return DB::table('collection_items as ci')
            ->join('bl_colors as c', 'c.id', '=', 'ci.color_id')
            ->where('ci.item_type', 'P')
            ->distinct()
            ->orderBy('c.name')
            ->get(['c.id', 'c.name', 'c.rgb']);
    }

    private function base(): Builder
    {
        $query = DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'ci.item_type')
                ->on('i.id', '=', 'ci.item_id'))
            ->leftJoin('bl_colors as c', 'c.id', '=', 'ci.color_id')
            ->where('ci.item_type', 'P')
            ->groupBy('ci.item_id', 'ci.color_id')
            ->select([
                'ci.item_id',
                'ci.color_id',
                DB::raw('COALESCE(i.name, ci.item_id) as name'),
                'c.name as color_name',
                'c.rgb as color_rgb',
                DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),

                // Spares are counted apart, not folded in: the accounting rule
                // keeps them out of a quantity, but a spare brick is still in
                // the box and hiding it would make the number a lie.
                DB::raw('COALESCE(SUM(CASE WHEN ci.counts = 1 THEN ci.qty END), 0) as total'),
                DB::raw("COALESCE(SUM(CASE WHEN ci.counts = 1 AND (ci.parent_item_type = 'S'
                    OR (ci.parent_item_type IS NULL AND e.item_type = 'S')) THEN ci.qty END), 0) as in_sets"),
                DB::raw("COALESCE(SUM(CASE WHEN ci.counts = 1 AND ci.parent_item_type = 'M'
                    THEN ci.qty END), 0) as in_minifigures"),
                DB::raw("COALESCE(SUM(CASE WHEN ci.counts = 1 AND ci.parent_item_type IS NULL
                    AND e.item_type = 'P' THEN ci.qty END), 0) as loose"),

                // An assembly is an entry with no catalog counterpart, so its
                // parts sit under no type at all. Without a bucket of their
                // own they would be held but be nowhere.
                DB::raw('COALESCE(SUM(CASE WHEN ci.counts = 1 AND ci.parent_item_type IS NULL
                    AND e.item_type IS NULL THEN ci.qty END), 0) as in_assemblies'),
                DB::raw('COALESCE(SUM(CASE WHEN ci.is_extra = 1 THEN ci.qty END), 0) as spares'),
                DB::raw('COALESCE(SUM(CASE WHEN ci.counts = 1 THEN ci.lost_qty END), 0) as lost'),

                DB::raw('MAX(ci.is_alternate) as has_alternate'),
                DB::raw('MAX(ci.is_counterpart) as has_counterpart'),
                DB::raw('MAX(ci.is_extra) as has_extra'),
            ]);

        $term = trim((string) ($this->filters['q'] ?? ''));

        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

            $query->where(fn ($where) => $where
                ->where('ci.item_id', 'like', "{$escaped}%")
                ->orWhere('i.name', 'like', "%{$escaped}%"));
        }

        if (($this->filters['color_id'] ?? null) !== null && $this->filters['color_id'] !== '') {
            $query->where('ci.color_id', $this->filters['color_id']);
        }

        // A part whose every occurrence is an alternate or a counterpart is
        // not in the collection at all: the alternate is the version that was
        // not used, and a counterpart is another lot counted elsewhere. Spares
        // do count as held, which is why they keep a row.
        // Parenthesised: a later havingRaw is joined with AND, and
        // "total > 0 OR spares > 0 AND in_sets > 0" binds the AND tighter than
        // the OR — every held part passed the placement filter.
        $query->havingRaw('(total > 0 OR spares > 0)');

        // Where a part sits is a property of the group, not of a row, so it
        // filters after the grouping.
        match ($this->filters['placement'] ?? null) {
            'set' => $query->havingRaw('in_sets > 0'),
            'minifigure' => $query->havingRaw('in_minifigures > 0'),
            'loose' => $query->havingRaw('loose > 0'),
            'assembly' => $query->havingRaw('in_assemblies > 0'),
            'lost' => $query->havingRaw('lost > 0'),
            default => null,
        };

        return $query;
    }
}
