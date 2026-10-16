<?php

namespace App\Collection\Queries;

use App\Catalog\Queries\ThemeOptions;
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
    /**
     * @param  string[]  $types  item types this section covers
     * @param  int|null  $themeId  выбранная тема: от неё зависит список подтем
     */
    public function __construct(
        private readonly array $types,
        private readonly ?int $themeId = null,
    ) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $paths = $this->paths();

        return [
            'types' => $this->types(),
            'themes' => ThemeOptions::roots($paths),
            'subthemes' => ThemeOptions::under($this->themeId, $paths),
            'themeRoot' => ThemeOptions::rootId($this->themeId),
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
     * Пути тем, которые представлены в разделе.
     *
     * Из них собираются и корни, и подтемы: предлагать тему, за которой ничего
     * нет, значит звать человека в пустой список.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function paths(): \Illuminate\Support\Collection
    {
        return DB::table('collection_entries as e')
            ->join('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->join('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->whereIn('e.item_type', $this->types)
            ->distinct()
            ->pluck('t.path');
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
