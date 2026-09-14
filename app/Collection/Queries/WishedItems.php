<?php

namespace App\Collection\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Желаемое, с теми же фильтрами, что и справочник.
 *
 * Поиск — обычный LIKE, а не полнотекстовый индекс справочника: желаемое это
 * десятки строк против 175 тысяч, и индекс здесь ничего не купил бы, зато
 * добавил бы второй источник правды, который надо держать в согласии.
 *
 * Варианты фильтров берутся из самого списка, а не из справочника: предлагать
 * год или тему, которых в желаемом нет, значит обещать пустой результат.
 */
class WishedItems
{
    /** @var array<string, mixed> */
    private array $filters = [];

    /** @var array{by: string, dir: string}|null */
    private ?array $sort = null;

    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /** @param array{by: string, dir: string}|null $sort */
    public function sort(?array $sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    public function paginate(int $perPage = 48): LengthAwarePaginator
    {
        return $this->ordered()->paginate($perPage)->withQueryString();
    }

    /** Весь список разом, без страниц: выгрузка отдаёт то же, что показано. */
    public function all(): Collection
    {
        return $this->ordered()->get();
    }

    private function ordered(): Builder
    {
        $query = $this->base()->select([
            'w.id as wish_id',
            'w.item_type as type',
            'w.item_id',
            'w.color_id',
            DB::raw('COALESCE(i.name, w.item_id) as name'),
            'i.year',
            'i.has_inventory',
            DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),
            't.path as theme',
            'c.name as color_name',
            'c.rgb as color_rgb',
        ]);

        $dir = ($this->sort['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        match ($this->sort['by'] ?? null) {
            'id' => $query->orderBy('w.item_id', $dir),
            'name' => $query->orderBy('name', $dir),
            'year' => $query->orderBy('i.year', $dir),
            default => null,
        };

        // Порядок добавления: последнее желание сверху. Он же разрыв ничьих —
        // без него строки с одинаковым ключом переставляются между страницами.
        return $query->orderByDesc('w.id');
    }

    /**
     * Что вообще есть в желаемом: типы, корневые темы и годы.
     *
     * @return array<string, array<int, mixed>>
     */
    public function facets(): array
    {
        $rows = DB::table('wishlist as w')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'w.item_type')
                ->on('i.id', '=', 'w.item_id'))
            ->leftJoin('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->distinct()
            ->get(['w.item_type', 't.path', 'i.year']);

        $roots = $rows->pluck('path')->filter()
            ->map(fn (string $path) => explode(' / ', $path)[0])
            ->unique()
            ->values();

        return [
            'types' => DB::table('bl_item_types')
                ->whereIn('code', $rows->pluck('item_type')->unique()->values())
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(fn ($row) => (array) $row)
                ->all(),
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
        $query = DB::table('wishlist as w')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'w.item_type')
                ->on('i.id', '=', 'w.item_id'))
            ->leftJoin('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->leftJoin('bl_colors as c', 'c.id', '=', 'w.color_id');

        $term = trim((string) ($this->filters['q'] ?? ''));

        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

            $query->where(fn ($where) => $where
                ->where('w.item_id', 'like', "{$escaped}%")
                ->orWhere('i.name', 'like', "%{$escaped}%"));
        }

        if (($this->filters['type'] ?? null)) {
            $query->where('w.item_type', $this->filters['type']);
        }

        if (($this->filters['year'] ?? null)) {
            $query->where('i.year', $this->filters['year']);
        }

        if (($this->filters['theme_id'] ?? null)) {
            $this->restrictToThemeSubtree($query, (int) $this->filters['theme_id']);
        }

        return $query;
    }

    /** Выбор темы захватывает всё поддерево, как в справочнике. */
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
            ->where(fn ($where) => $where->where('path', $path)->orWhere('path', 'like', $escaped.' / %')));
    }
}
