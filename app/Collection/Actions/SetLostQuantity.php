<?php

namespace App\Collection\Actions;

use App\Collection\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Records how many of one lot went missing.
 *
 * Marking a whole minifigure lost does not touch the rows beneath it. Its
 * parts are still listed as belonging to that figure, and the "Missing
 * figures" status reads the figure's own row. Zeroing the children would make
 * it impossible to say later that the figure came back.
 */
class SetLostQuantity
{
    public function __construct(private readonly RecalculateEntryFlags $flags) {}

    public function handle(Item $item, int $lost): Item
    {
        return DB::transaction(function () use ($item, $lost) {
            // Cannot lose more than there were, and cannot lose a negative
            // number. Clamped rather than rejected: the input is a spinner and
            // an out-of-range value is a slip, not an attack.
            $item->lost_qty = max(0, min($lost, $item->qty));
            $item->save();

            $this->flags->handle($item->entry);

            return $item;
        });
    }
}
