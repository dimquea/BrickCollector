<?php

namespace App\Collection\Actions;

use App\Collection\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes the two derived statuses of an entry.
 *
 * They are cached on the entry because the sets listing filters on them, and
 * a subquery per card would make that list crawl. Anything that changes
 * lost_qty must call this.
 */
class RecalculateEntryFlags
{
    public function handle(Entry $entry): Entry
    {
        $row = DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->where('counts', 1)
            ->where('lost_qty', '>', 0)
            ->selectRaw("
                COUNT(*) as any_missing,
                COALESCE(SUM(CASE WHEN item_type = 'M' THEN 1 END), 0) as figures_missing
            ")
            ->first();

        $entry->forceFill([
            // A lost spare is not a missing piece: the set is still complete
            // without it. Rows that do not count are excluded above, and
            // spares are among them.
            'flag_incomplete' => $row->any_missing > 0,
            'flag_missing_figs' => $row->figures_missing > 0,
        ])->save();

        return $entry;
    }
}
