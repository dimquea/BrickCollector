<?php

namespace App\Collection\Actions;

use App\Collection\Models\Entry;
use App\Collection\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves parts between the loose pile and an assembly.
 *
 * Nothing is created or destroyed here, only moved: the collection holds the
 * same bricks before and after, and only the answer to "where is it" changes.
 * That is what lets the part page keep counting with a plain SUM.
 *
 * Taking from the loose pile spends whole lots, oldest first. A lot is a
 * purchase — a date, a price, a drawer — and splitting one across an assembly
 * and the shelf would leave two halves claiming the same receipt. A lot spent
 * to the last brick is deleted; there is nothing left of it to describe.
 *
 * Giving back always makes a new lot, with no date and no price. Where the
 * brick came from was forgotten when it went into the assembly, and inventing
 * a purchase for it would be worse than admitting that.
 *
 * A part can have an inventory of its own (a length of track is a rail and
 * sleepers), so rows underneath move with it, scaled to the quantity.
 */
class MoveParts
{
    /** Takes parts out of the loose pile and puts them into an assembly. */
    public function intoAssembly(Entry $assembly, string $itemId, int $colorId, int $qty): void
    {
        DB::transaction(function () use ($assembly, $itemId, $colorId, $qty) {
            $remaining = $qty;

            $lots = Entry::where('item_type', 'P')
                ->where('item_id', $itemId)
                ->where('color_id', $colorId)
                ->orderByRaw('COALESCE(acquired_at, created_at)')
                ->orderBy('id')
                ->get();

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $root = $lot->roots()->first();

                // What is marked missing is not there to be built with.
                $available = $root === null ? 0 : max(0, $root->qty - $root->lost_qty);

                if ($available === 0) {
                    continue;
                }

                $take = min($available, $remaining);
                $remaining -= $take;

                $this->put($assembly, $root, $take);

                if ($take === $root->qty) {
                    $lot->delete();
                } else {
                    $this->scale($root, $root->qty - $take);
                }
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages([
                    'qty' => __('app.assembly.not_enough', ['count' => $qty - $remaining]),
                ]);
            }
        });
    }

    /**
     * Moves one particular lot into an assembly, in whole or in part.
     *
     * Adding a part from the catalogue straight into an assembly goes through
     * here: the part is entered as a lot, the way everything else is, and then
     * moved. Taking it by part and colour instead would spend the oldest lot
     * on the shelf — a purchase with a date and a price — and leave the one
     * just entered lying about.
     */
    public function fromLot(Entry $assembly, Entry $lot, int $qty): void
    {
        DB::transaction(function () use ($assembly, $lot, $qty) {
            $root = $lot->roots()->first();

            if ($root === null) {
                return;
            }

            $take = min($qty, $root->qty);

            $this->put($assembly, $root, $take);

            if ($take === $root->qty) {
                $lot->delete();
            } else {
                $this->scale($root, $root->qty - $take);
            }
        });
    }

    /** Gives parts back from an assembly to the loose pile, as a new lot. */
    public function outOfAssembly(Entry $assembly, Item $row, int $qty): Entry
    {
        return DB::transaction(function () use ($assembly, $row, $qty) {
            $qty = min($qty, $row->qty);

            $lot = Entry::create([
                'item_type' => 'P',
                'item_id' => $row->item_id,
                'color_id' => $row->color_id,
                'flag_incomplete' => false,
                'flag_missing_figs' => false,
            ]);

            $this->copy($row, $lot, $qty, null, null);

            if ($qty === $row->qty) {
                $row->delete();
            } else {
                $this->scale($row, $row->qty - $qty);
            }

            return $lot;
        });
    }

    /**
     * Adds to the row an assembly already has for this part and colour, or
     * copies a new one in. One row per part and colour: an assembly is a list
     * of what it is made of, not of where the pieces were bought.
     */
    private function put(Entry $assembly, Item $source, int $qty): void
    {
        $existing = $assembly->roots()
            ->where('item_type', 'P')
            ->where('item_id', $source->item_id)
            ->where('color_id', $source->color_id)
            ->first();

        if ($existing === null) {
            $this->copy($source, $assembly, $qty, null, null);

            return;
        }

        $this->scale($existing, $existing->qty + $qty);
    }

    /** Copies a row and everything under it, scaled to a new quantity. */
    private function copy(Item $source, Entry $target, int $qty, ?int $parentId, ?string $parentType): int
    {
        $id = $target->items()->create([
            'parent_id' => $parentId,
            'parent_item_type' => $parentType,
            'item_type' => $source->item_type,
            'item_id' => $source->item_id,
            'color_id' => $source->color_id,
            'qty' => $qty,
            // What was missing stays with the lot it was missing from.
            'lost_qty' => 0,
            'is_extra' => $source->is_extra,
            'is_alternate' => $source->is_alternate,
            'is_counterpart' => $source->is_counterpart,
            'match_id' => $source->match_id,
            'counts' => $source->counts,
        ])->id;

        $old = max(1, $source->qty);

        foreach ($source->children as $child) {
            $this->copy($child, $target, intdiv($child->qty * $qty, $old), $id, $source->item_type);
        }

        return $id;
    }

    /** Resizes a row, taking whatever is underneath it along. */
    private function scale(Item $row, int $qty): void
    {
        $old = max(1, $row->qty);

        $row->update([
            'qty' => $qty,
            'lost_qty' => min($row->lost_qty, $qty),
        ]);

        foreach ($row->children as $child) {
            $this->scale($child, intdiv($child->qty * $qty, $old));
        }
    }
}
