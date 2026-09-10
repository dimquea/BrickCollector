<?php

namespace App\Collection\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Minifigures held across the collection, one row per figure.
 *
 * A figure turns up two ways: built into sets, and owned on its own. Both are
 * the same figure, so they are counted together and the filter says which kind
 * to look at rather than splitting the section in two.
 */
class MinifigureTotals
{
    /** @var array<string, mixed> */
    private array $filters = [];

    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    public function paginate(int $perPage = 24): LengthAwarePaginator
    {
        return $this->base()->orderBy('name')->paginate($perPage)->withQueryString();
    }

    public function one(string $itemId): ?object
    {
        return $this->base()->where('ci.item_id', $itemId)->first();
    }

    /** Themes and years actually represented, for the filters. */
    public function facets(): array
    {
        $rows = DB::table('collection_items as ci')
            ->join('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'ci.item_type')
                ->on('i.id', '=', 'ci.item_id'))
            ->leftJoin('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->where('ci.item_type', 'M')
            ->where('ci.counts', 1)
            ->distinct()
            ->get(['t.path', 'i.year']);

        $roots = $rows->pluck('path')->filter()
            ->map(fn (string $path) => explode(' / ', $path)[0])
            ->unique()
            ->values();

        return [
            'themes' => $roots->isEmpty() ? [] : DB::table('bl_themes')
                ->whereIn('path', $roots)
                ->where('depth', 0)
                ->orderBy('path')
                ->get(['id', 'path'])
                ->map(fn ($row) => (array) $row)
                ->all(),
            'years' => $rows->pluck('year')->filter()->unique()->sortDesc()->values()->all(),
        ];
    }

    private function base(): Builder
    {
        $query = DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'ci.item_type')
                ->on('i.id', '=', 'ci.item_id'))
            ->leftJoin('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->where('ci.item_type', 'M')
            ->groupBy('ci.item_id')
            ->select([
                'ci.item_id',
                DB::raw('COALESCE(i.name, ci.item_id) as name'),
                'i.year',
                't.path as theme',
                DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),

                DB::raw('COALESCE(SUM(CASE WHEN ci.counts = 1 THEN ci.qty END), 0) as total'),

                // A figure is loose only when it is the copy itself: an entry
                // of its own, sitting at the root of its tree. Everything else
                // is built into something.
                //
                // Not "parent_item_type IS NOT NULL": the minifigures of a set
                // are root rows of that set's tree and have no parent, so that
                // test missed most of them.
                DB::raw("COALESCE(SUM(CASE WHEN ci.counts = 1
                    AND NOT (ci.parent_item_type IS NULL AND e.item_type = 'M')
                    THEN ci.qty END), 0) as in_sets"),
                DB::raw("COALESCE(SUM(CASE WHEN ci.counts = 1 AND ci.parent_item_type IS NULL
                    AND e.item_type = 'M' THEN ci.qty END), 0) as loose"),

                // How many owned copies hold it, which is what the card shows
                // beside the count — three of a figure across two sets reads
                // differently from three in one.
                DB::raw("COUNT(DISTINCT CASE WHEN ci.counts = 1
                    AND NOT (ci.parent_item_type IS NULL AND e.item_type = 'M')
                    THEN ci.entry_id END) as entries"),

                DB::raw('COALESCE(SUM(CASE WHEN ci.counts = 1 THEN ci.lost_qty END), 0) as lost'),
            ]);

        $term = trim((string) ($this->filters['q'] ?? ''));

        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

            $query->where(fn ($where) => $where
                ->where('ci.item_id', 'like', "{$escaped}%")
                ->orWhere('i.name', 'like', "%{$escaped}%"));
        }

        if (($this->filters['year'] ?? null)) {
            $query->where('i.year', $this->filters['year']);
        }

        if (($this->filters['theme_id'] ?? null)) {
            $this->restrictToThemeSubtree($query, (int) $this->filters['theme_id']);
        }

        if (($this->filters['tag_id'] ?? null)) {
            $this->restrictToTaggedCopies($query, (int) $this->filters['tag_id']);
        }

        // Parenthesised: the placement filter below is joined with AND, and an
        // unbracketed OR would bind looser and let everything through.
        $query->havingRaw('(total > 0)');

        match ($this->filters['placement'] ?? null) {
            'set' => $query->havingRaw('in_sets > 0'),
            'loose' => $query->havingRaw('loose > 0'),
            default => null,
        };

        return $query;
    }

    /**
     * A figure built into a set has no entry of its own, and it does not
     * inherit the set's tags: tagging a set "for sale" says nothing about the
     * minifigure inside it. So the tag filter looks only at standalone copies.
     *
     * Written as a subquery over item ids rather than a condition on the rows:
     * filtering rows before the grouping would drop the in-set rows of a
     * tagged figure and quietly change every count on its card.
     */
    private function restrictToTaggedCopies(Builder $query, int $tagId): void
    {
        $query->whereIn('ci.item_id', fn ($sub) => $sub
            ->from('collection_items as tagged')
            ->join('collection_entries as owner', 'owner.id', '=', 'tagged.entry_id')
            ->join('entry_tags', 'entry_tags.entry_id', '=', 'owner.id')
            ->where('tagged.item_type', 'M')
            ->whereNull('tagged.parent_item_type')
            ->where('owner.item_type', 'M')
            ->where('entry_tags.tag_id', $tagId)
            ->select('tagged.item_id'));
    }

    private function restrictToThemeSubtree(Builder $query, int $themeId): void
    {
        $path = DB::table('bl_themes')->where('id', $themeId)->value('path');

        if ($path === null) {
            return;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path);

        $query->whereIn('i.theme_id', fn ($sub) => $sub
            ->from('bl_themes')
            ->select('id')
            ->where(fn ($w) => $w->where('path', $path)->orWhere('path', 'like', $escaped.' / %')));
    }
}
