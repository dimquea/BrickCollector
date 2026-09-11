<?php

namespace App\Collection\Actions;

use App\Collection\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Changes how many of a part a loose lot holds.
 *
 * A part can have an inventory of its own — a length of track is a rail and
 * its sleepers — and those rows were multiplied by the lot's quantity when it
 * was added. They are scaled with it, or the lot would claim 2 rails and 20
 * sleepers' worth of track. Each row divides evenly, since it was built as
 * a multiple of the old quantity.
 *
 * A loss can not outnumber what is there, so it is trimmed to the new count.
 */
class ResizeLot
{
    public function handle(Entry $entry, int $qty): void
    {
        DB::transaction(function () use ($entry, $qty) {
            $root = $entry->roots()->firstOrFail();
            $old = max(1, $root->qty);

            foreach ($entry->items()->get() as $row) {
                $next = $row->id === $root->id ? $qty : intdiv($row->qty * $qty, $old);

                $row->update([
                    'qty' => $next,
                    'lost_qty' => min($row->lost_qty, $next),
                ]);
            }
        });
    }
}
