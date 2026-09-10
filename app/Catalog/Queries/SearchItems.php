<?php

namespace App\Catalog\Queries;

use App\Catalog\Models\Item;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Catalog search.
 *
 * Text goes through FTS5 rather than LIKE '%...%': across 175,000 rows the
 * latter cannot use an index and measured 26 ms per query.
 *
 * Names and item numbers live in the same index, in separate columns, so one
 * MATCH covers both ways people look things up. Splitting them into two
 * queries joined by UNION was the first attempt and it was worse in every way:
 * SQLite had to materialise and de-duplicate both sides (1.8 s on a common
 * word), and the combined result had no ranking left, so a search for
 * "millennium falcon" led with books and keyrings.
 */
class SearchItems
{
    /**
     * Relevance weights for bm25(), in column order: name, ident.
     *
     * The number is weighted higher because typing one is unambiguous — a
     * person entering "10270" wants that set, not every set whose description
     * happens to contain the digits.
     */
    private const WEIGHTS = [1.0, 4.0];

    /** @var array<string, mixed> */
    private array $filters = [];

    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    public function paginate(int $perPage = 48): LengthAwarePaginator
    {
        $term = trim((string) ($this->filters['q'] ?? ''));

        $query = Item::query()
            ->from('bl_items as i')
            ->select('i.*')
            ->with('theme');

        if ($term !== '') {
            // The FTS table must not be aliased: SQLite resolves "x MATCH ?"
            // against real table names only, and an alias fails with
            // "no such column: x".
            $matches = DB::table('bl_items_fts')
                ->selectRaw('type, item_id as id, bm25(bl_items_fts, ?, ?) as rank', self::WEIGHTS)
                ->whereRaw('bl_items_fts MATCH ?', [$this->ftsQuery($term)]);

            $query
                ->addSelect('k.rank')
                ->joinSub($matches, 'k', fn ($join) => $join
                    ->on('k.type', '=', 'i.type')
                    ->on('k.id', '=', 'i.id'));
        }

        foreach (['type' => 'i.type', 'year' => 'i.year'] as $filter => $column) {
            $value = $this->filters[$filter] ?? null;

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        if (($this->filters['theme_id'] ?? null)) {
            $this->restrictToThemeSubtree($query, (int) $this->filters['theme_id']);
        }

        if (! empty($this->filters['has_inventory'])) {
            $query->where('i.has_inventory', 1);
        }

        // bm25() returns a negative score, best first, so plain ascending
        // order is most-relevant first.
        $term !== ''
            ? $query->orderBy('k.rank')->orderBy('i.type')->orderBy('i.id')
            : $query->orderBy('i.type')->orderBy('i.id');

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Picking "Star Wars" must find everything under it, not only the handful
     * of items sitting exactly at that node. The tree is matched by path
     * prefix, which the UNIQUE index on bl_themes.path can serve.
     */
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

    /** Theme names contain characters LIKE treats as wildcards. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Turns typed words into an FTS5 expression.
     *
     * Every token is quoted so that FTS5 syntax a person happens to type
     * ("OR", "*", "-", a stray quote) is matched literally instead of being
     * executed or raising a syntax error. The last token carries a prefix
     * wildcard so results narrow while typing.
     */
    private function ftsQuery(string $term): string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (! $tokens) {
            // An empty MATCH is a syntax error in FTS5; match nothing instead.
            return '"'.uniqid('nothing', true).'"';
        }

        $last = array_key_last($tokens);

        foreach ($tokens as $index => $token) {
            $tokens[$index] = '"'.str_replace('"', '""', $token).'"'.($index === $last ? '*' : '');
        }

        return implode(' ', $tokens);
    }
}
