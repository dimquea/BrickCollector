<?php

namespace App\Collection\Queries;

use Illuminate\Support\Facades\DB;

/**
 * The filter options a section can actually offer.
 *
 * Drawn from what is owned, not from what the catalog knows. Offering Gear
 * when no gear is owned, or every year since 1949 for a shelf holding four
 * sets, invites the person to pick something that returns nothing — and a
 * filter that can produce an empty result is worse than no filter, because it
 * looks like the collection lost something.
 */
class EntryFacets
{
    /** @param string[] $types item types this section covers */
    public function __construct(private readonly array $types) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'types' => $this->types(),
            'themes' => $this->themes(),
            'years' => $this->years(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function types(): array
    {
        return DB::table('collection_entries as e')
            ->join('bl_item_types as t', 't.code', '=', 'e.item_type')
            ->whereIn('e.item_type', $this->types)
            ->distinct()
            ->orderBy('t.code')
            ->get(['t.code', 't.name'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Root themes that are represented.
     *
     * Roots rather than exact paths, because choosing one matches its whole
     * subtree — the same as in the catalog. A shelf of Star Wars sets spread
     * across five sub-themes offers "Star Wars" once, not five deep paths.
     *
     * @return array<int, array<string, mixed>>
     */
    private function themes(): array
    {
        $paths = DB::table('collection_entries as e')
            ->join('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->join('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->whereIn('e.item_type', $this->types)
            ->distinct()
            ->pluck('t.path');

        $roots = $paths
            ->map(fn (string $path) => explode(' / ', $path)[0])
            ->unique()
            ->values();

        if ($roots->isEmpty()) {
            return [];
        }

        return DB::table('bl_themes')
            ->whereIn('path', $roots)
            ->where('depth', 0)
            ->orderBy('path')
            ->get(['id', 'path'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return int[] newest first */
    private function years(): array
    {
        return DB::table('collection_entries as e')
            ->join('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->whereIn('e.item_type', $this->types)
            ->whereNotNull('i.year')
            ->distinct()
            ->orderByDesc('i.year')
            ->pluck('i.year')
            ->map(fn ($year) => (int) $year)
            ->all();
    }
}
