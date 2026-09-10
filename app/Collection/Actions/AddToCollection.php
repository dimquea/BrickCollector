<?php

namespace App\Collection\Actions;

use App\Catalog\Models\Item as CatalogItem;
use App\Catalog\Queries\ItemInventory;
use App\Collection\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Puts a catalog item into the collection.
 *
 * The inventory is copied, not referenced. A physical set does not change when
 * BrickLink edits its listing, every lot needs its own count of what went
 * missing, and a user-made assembly has no catalog entry to point at.
 */
class AddToCollection
{
    public function __construct(private readonly ItemInventory $inventory) {}

    /**
     * @param  array<string, mixed>  $meta  acquired_at, price, source_id, storage_id, note
     */
    public function handle(CatalogItem $item, array $meta = []): Entry
    {
        return DB::transaction(function () use ($item, $meta) {
            $entry = Entry::create([
                'item_type' => $item->type,
                'item_id' => $item->id,
                'color_id' => $item->type === 'P' ? ($meta['color_id'] ?? $item->image_color_id) : null,
                'acquired_at' => $meta['acquired_at'] ?? null,
                'price' => $meta['price'] ?? null,
                'source_id' => $meta['source_id'] ?? null,
                'storage_id' => $meta['storage_id'] ?? null,
                'note' => $meta['note'] ?? null,
            ]);

            $tree = $item->has_inventory ? $this->inventory->tree($item->type, $item->id) : [];

            if ($item->type === 'S') {
                // A set is the entry itself; its lots are the top of the tree.
                $this->store($entry, $tree, null, null, 1, true);
            } else {
                // Anything else is a countable thing in its own right — a
                // minifigure, a loose part — so it gets a row, with whatever
                // it is made of underneath.
                $qty = max(1, (int) ($meta['qty'] ?? 1));

                $rootId = $this->row($entry, [
                    'type' => $item->type,
                    'id' => $item->id,
                    'color_id' => $entry->color_id ?? 0,
                    'qty' => $qty,
                    'is_extra' => false,
                    'is_alternate' => false,
                    'is_counterpart' => false,
                    'match_id' => 0,
                ], null, null);

                $this->store($entry, $tree, $rootId, $item->type, $qty, true);
            }

            return $entry;
        });
    }

    /**
     * Writes one level of the tree and recurses.
     *
     * Quantities are multiplied down the tree. A box holding 36 packets of one
     * part means 36 of that part in the collection, and part counting is a
     * single SUM(qty) over these rows — it does not walk back up looking for
     * ancestors to multiply by.
     *
     * A lot that does not count takes its whole subtree with it. The eleven
     * alternates of a random packet are not in the box, and neither are the
     * minifigures inside them.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function store(
        Entry $entry,
        array $nodes,
        ?int $parentId,
        ?string $parentType,
        int $multiplier,
        bool $parentCounts,
    ): void {
        foreach ($nodes as $node) {
            $counts = $parentCounts
                && ! $node['is_extra']
                && ! $node['is_alternate']
                && ! $node['is_counterpart'];

            $id = $this->row($entry, $node, $parentId, $parentType, $multiplier, $counts);

            if ($node['children']) {
                $this->store($entry, $node['children'], $id, $node['type'], $multiplier * $node['qty'], $counts);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function row(
        Entry $entry,
        array $node,
        ?int $parentId,
        ?string $parentType,
        int $multiplier = 1,
        bool $counts = true,
    ): int {
        return $entry->items()->create([
            'parent_id' => $parentId,
            'parent_item_type' => $parentType,
            'item_type' => $node['type'],
            'item_id' => $node['id'],
            'color_id' => $node['color_id'],
            'qty' => $node['qty'] * $multiplier,
            'lost_qty' => 0,
            'is_extra' => $node['is_extra'],
            'is_alternate' => $node['is_alternate'],
            'is_counterpart' => $node['is_counterpart'],
            'match_id' => $node['match_id'],
            'counts' => $counts,
        ])->id;
    }
}
