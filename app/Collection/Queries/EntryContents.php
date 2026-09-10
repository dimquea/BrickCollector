<?php

namespace App\Collection\Queries;

use App\Collection\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * What one owned copy consists of, as a tree.
 *
 * Read from collection_items rather than the catalog: the copy was frozen when
 * it was added, and it carries losses of its own.
 */
class EntryContents
{
    /**
     * @return array<int, array<string, mixed>> nested by "children"
     */
    public function tree(Entry $entry): array
    {
        $rows = DB::table('collection_items as ci')
            ->leftJoin('bl_items as it', fn ($join) => $join
                ->on('it.type', '=', 'ci.item_type')
                ->on('it.id', '=', 'ci.item_id'))
            ->leftJoin('bl_colors as c', 'c.id', '=', 'ci.color_id')
            ->where('ci.entry_id', $entry->id)
            ->orderBy('ci.item_type')
            ->orderBy('ci.item_id')
            ->get([
                'ci.id', 'ci.parent_id', 'ci.item_type', 'ci.item_id', 'ci.color_id',
                'ci.qty', 'ci.lost_qty', 'ci.counts',
                'ci.is_extra', 'ci.is_alternate', 'ci.is_counterpart', 'ci.match_id',
                'it.name', 'it.image_color_id',
                'c.name as color_name', 'c.rgb as color_rgb',
            ]);

        $nodes = [];

        foreach ($rows as $row) {
            $nodes[$row->id] = [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'type' => $row->item_type,
                'item_id' => $row->item_id,
                'name' => $row->name ?? $row->item_id,
                'qty' => (int) $row->qty,
                'lost_qty' => (int) $row->lost_qty,
                'counts' => (bool) $row->counts,
                'color_id' => (int) $row->color_id,
                'color_name' => $row->color_name,
                'color_rgb' => $row->color_rgb,
                'image_color_id' => (int) ($row->image_color_id ?? 0),
                'is_extra' => (bool) $row->is_extra,
                'is_alternate' => (bool) $row->is_alternate,
                'is_counterpart' => (bool) $row->is_counterpart,
                'match_id' => (int) $row->match_id,
                'children' => [],
            ];
        }

        // Assembled bottom-up by reference so the whole tree costs one pass,
        // not a query or a scan per node.
        $roots = [];

        foreach ($nodes as $id => &$node) {
            if ($node['parent_id'] !== null && isset($nodes[$node['parent_id']])) {
                $nodes[$node['parent_id']]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }

        unset($node);

        return $roots;
    }

    /**
     * @return array{parts: int, minifigures: int, lost: int, lost_lots: int, extras: int}
     */
    public function totals(Entry $entry): array
    {
        $row = DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN item_type = 'P' AND counts = 1 THEN qty END), 0)      as parts,
                COALESCE(SUM(CASE WHEN item_type = 'M' AND counts = 1 THEN qty END), 0)      as minifigures,
                COALESCE(SUM(CASE WHEN counts = 1 THEN lost_qty END), 0)                     as lost,
                COALESCE(SUM(CASE WHEN counts = 1 AND lost_qty > 0 THEN 1 END), 0)           as lost_lots,
                COALESCE(SUM(CASE WHEN is_extra = 1 THEN qty END), 0)                        as extras
            ")
            ->first();

        return [
            'parts' => (int) $row->parts,
            'minifigures' => (int) $row->minifigures,
            'lost' => (int) $row->lost,
            'lost_lots' => (int) $row->lost_lots,
            'extras' => (int) $row->extras,
        ];
    }
}
