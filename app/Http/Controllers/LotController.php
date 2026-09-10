<?php

namespace App\Http\Controllers;

use App\Collection\Actions\SetLostQuantity;
use App\Collection\Models\Item as CollectionItem;
use App\Collection\Queries\EntryContents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One lot of an owned copy.
 *
 * Its own controller because a lot belongs to a set, a minifigure or a part
 * alike, and the sections that show it should not each carry a copy of this.
 */
class LotController extends Controller
{
    /**
     * Answers JSON, not a redirect.
     *
     * Inertia re-renders the page on every response, and re-rendering
     * collapses every expanded accordion — which is exactly where this field
     * lives, several levels down.
     */
    public function updateLost(
        Request $request,
        CollectionItem $item,
        SetLostQuantity $setLost,
        EntryContents $contents,
    ): JsonResponse {
        $validated = $request->validate([
            'lost_qty' => ['required', 'integer', 'min:0'],
        ]);

        $item = $setLost->handle($item, $validated['lost_qty']);
        $entry = $item->entry->refresh();

        return response()->json([
            'lost_qty' => $item->lost_qty,
            'totals' => $contents->totals($entry),
            'flags' => [
                'flag_incomplete' => $entry->flag_incomplete,
                'flag_missing_figs' => $entry->flag_missing_figs,
            ],
        ]);
    }
}
