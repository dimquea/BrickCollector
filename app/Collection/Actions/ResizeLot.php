<?php

namespace App\Collection\Actions;

use App\Collection\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Changes how many of a part a loose lot holds.
 *
 * A lot is one row, whatever the part is made of. A composite part — a torso
 * with its arms, a length of track with its sleepers — goes in whole: those
 * pieces are the same plastic described twice, and holding them as rows of
 * their own would make the collection bigger than the drawer. So there is
 * nothing underneath to scale.
 *
 * A loss can not outnumber what is there, so it is trimmed to the new count.
 * The interface refuses to shrink a lot below what is missing from it; this is
 * the floor under that, for every other way in.
 */
class ResizeLot
{
    public function handle(Entry $entry, int $qty): void
    {
        $root = $entry->roots()->firstOrFail();

        $root->update([
            'qty' => $qty,
            'lost_qty' => min($root->lost_qty, $qty),
        ]);
    }
}
