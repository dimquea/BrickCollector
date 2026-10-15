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
    public function __construct(
        private readonly ItemInventory $inventory,
        private readonly WriteContents $contents,
    ) {}

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
                // Set here rather than left to the column default: otherwise
                // the object handed back carries null until something reloads
                // it, and callers reasonably expect a boolean.
                'flag_incomplete' => false,
                'flag_missing_figs' => false,
            ]);

            // Опись есть не только у наборов, но раскрывается не всякая.
            // Составная деталь — торс с руками, ноги в сборе — физически одна
            // вещь: её части внутрь не кладутся, иначе тот же кирпичик будет
            // посчитан дважды, и сам, и по частям. Правило то же, что и для
            // вложенных уровней, и живёт оно там же, в одном экземпляре.
            $expandable = in_array($item->type, ItemInventory::EXPANDABLE, true);

            $tree = $item->has_inventory && $expandable
                ? $this->inventory->tree($item->type, $item->id)
                : [];

            if ($item->type === 'S') {
                // A set is the entry itself; its lots are the top of the tree.
                $this->contents->store($entry, $tree, null, null, 1, true);
            } else {
                // Anything else is a countable thing in its own right — a
                // minifigure, a loose part — so it gets a row, with whatever
                // it is made of underneath.
                $qty = max(1, (int) ($meta['qty'] ?? 1));

                $rootId = $this->contents->root(
                    $entry,
                    $item->type,
                    $item->id,
                    (int) ($entry->color_id ?? 0),
                    $qty,
                );

                $this->contents->store($entry, $tree, $rootId, $item->type, $qty, true);
            }

            return $entry;
        });
    }
}
