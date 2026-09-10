<?php

namespace App\Collection\Queries;

use App\Collection\Models\Entry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Owned copies, filtered the way the catalog is.
 *
 * Text search is a plain LIKE rather than the FTS index the catalog uses. A
 * collection is hundreds of rows where the catalog is 175,000, and the search
 * has to run against the joined catalog name anyway — an index would buy
 * nothing and cost a second source of truth to keep in step.
 */
class SearchEntries
{
    /** @var array<string, mixed> */
    private array $filters = [];

    /** @var string[] */
    private array $types = [];

    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /** @param string[] $types item types this section covers */
    public function ofTypes(array $types): self
    {
        $this->types = $types;

        return $this;
    }

    public function paginate(int $perPage = 24): LengthAwarePaginator
    {
        $query = Entry::query()
            ->from('collection_entries as e')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->leftJoin('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->select([
                'e.*',
                'i.name as item_name',
                'i.image_color_id',
                'i.year',
                't.path as theme',
                't.id as theme_id',
            ]);

        if ($this->types) {
            $query->whereIn('e.item_type', $this->types);
        }

        $term = trim((string) ($this->filters['q'] ?? ''));

        if ($term !== '') {
            $escaped = $this->escapeLike($term);

            $query->where(fn ($where) => $where
                ->where('i.name', 'like', "%{$escaped}%")
                ->orWhere('e.item_id', 'like', "{$escaped}%")
                // An assembly has no catalog item, so its own name is all
                // there is to match on.
                ->orWhere('e.name', 'like', "%{$escaped}%"));
        }

        if (($this->filters['type'] ?? null)) {
            $query->where('e.item_type', $this->filters['type']);
        }

        if (($this->filters['year'] ?? null)) {
            $query->where('i.year', $this->filters['year']);
        }

        if (($this->filters['theme_id'] ?? null)) {
            $this->restrictToThemeSubtree($query, (int) $this->filters['theme_id']);
        }

        if (($this->filters['status_id'] ?? null)) {
            $query->whereExists(fn ($sub) => $sub
                ->from('entry_statuses')
                ->whereColumn('entry_statuses.entry_id', 'e.id')
                ->where('entry_statuses.status_id', $this->filters['status_id']));
        }

        if (($this->filters['tag_id'] ?? null)) {
            $query->whereExists(fn ($sub) => $sub
                ->from('entry_tags')
                ->whereColumn('entry_tags.entry_id', 'e.id')
                ->where('entry_tags.tag_id', $this->filters['tag_id']));
        }

        // The two derived statuses filter like any other, which is the whole
        // point of caching them on the row.
        foreach (['incomplete' => 'flag_incomplete', 'missing_figs' => 'flag_missing_figs'] as $filter => $column) {
            if (! empty($this->filters[$filter])) {
                $query->where("e.{$column}", 1);
            }
        }

        return $query->orderByDesc('e.id')->paginate($perPage)->withQueryString();
    }

    /** Choosing a theme includes everything under it, as in the catalog. */
    private function restrictToThemeSubtree($query, int $themeId): void
    {
        $path = DB::table('bl_themes')->where('id', $themeId)->value('path');

        if ($path === null) {
            return;
        }

        $query->whereIn('i.theme_id', fn ($sub) => $sub
            ->from('bl_themes')
            ->select('id')
            ->where(fn ($w) => $w
                ->where('path', $path)
                ->orWhere('path', 'like', $this->escapeLike($path).' / %')));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
