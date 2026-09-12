<?php

namespace App\Collection\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Parts lying loose, grouped by part and colour.
 *
 * What an assembly can be built from. Lots are summed together here: a person
 * putting a model together thinks "I have eleven black 2x4", not "four from
 * March and seven from a bag off Avito" — which lot is spent is a bookkeeping
 * question, answered by MoveParts.
 *
 * What is marked missing is subtracted: a brick that has gone astray is not
 * available to build with.
 */
class LooseParts
{
    /**
     * @param  array<string, mixed>  $filters  q, color_id
     * @return Collection<int, array<string, mixed>>
     */
    public function available(array $filters = [], int $limit = 200): Collection
    {
        $query = DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'ci.item_type')
                ->on('i.id', '=', 'ci.item_id'))
            ->leftJoin('bl_colors as c', 'c.id', '=', 'ci.color_id')
            ->where('e.item_type', 'P')
            ->whereNull('ci.parent_id')
            ->where('ci.item_type', 'P')
            ->groupBy('ci.item_id', 'ci.color_id')
            ->havingRaw('SUM(ci.qty - ci.lost_qty) > 0')
            ->orderBy('i.name')
            ->orderBy('ci.item_id')
            ->orderBy('c.name')
            ->limit($limit);

        $term = trim((string) ($filters['q'] ?? ''));

        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

            $query->where(fn ($where) => $where
                ->where('ci.item_id', 'like', "{$escaped}%")
                ->orWhere('i.name', 'like', "%{$escaped}%"));
        }

        if (($filters['color_id'] ?? null) !== null && $filters['color_id'] !== '') {
            $query->where('ci.color_id', $filters['color_id']);
        }

        return $query
            ->get([
                'ci.item_id',
                'ci.color_id',
                DB::raw('COALESCE(i.name, ci.item_id) as name'),
                'c.name as color_name',
                'c.rgb as color_rgb',
                DB::raw('SUM(ci.qty - ci.lost_qty) as available'),
            ])
            ->map(fn ($row) => [
                'item_id' => $row->item_id,
                'color_id' => (int) $row->color_id,
                'name' => $row->name,
                'color_name' => $row->color_name,
                'color_rgb' => $row->color_rgb,
                'available' => (int) $row->available,
            ]);
    }

    /**
     * Colours the loose pile actually holds, for the filter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function colours(): array
    {
        return DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->join('bl_colors as c', 'c.id', '=', 'ci.color_id')
            ->where('e.item_type', 'P')
            ->whereNull('ci.parent_id')
            ->where('ci.item_type', 'P')
            ->distinct()
            ->orderBy('c.name')
            ->get(['c.id', 'c.name', 'c.rgb'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
