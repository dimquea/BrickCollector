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
    /** У чего бывает комментарий: фигурка и поднабор в составе экземпляра. */
    private const NOTED = ['M', 'S'];

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

    /**
     * Комментарий у фигурки или поднабора в составе экземпляра — особая
     * отметка вроде «трещина на ноге».
     *
     * Только у них: у детали в таблице для него нет места, и показывать его
     * там негде, а принять значение, которое нигде не увидишь, — хуже, чем
     * отказать.
     */
    public function updateNote(Request $request, CollectionItem $item): JsonResponse
    {
        // Корень отдельной фигурки — это сам экземпляр, и заметка у него своя.
        $inside = $item->parent_id !== null || $item->entry->item_type === 'S';

        abort_unless($inside && in_array($item->item_type, self::NOTED, true), 404);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $note = trim((string) ($validated['note'] ?? ''));

        $item->update(['note' => $note === '' ? null : $note]);

        return response()->json(['note' => $item->note]);
    }
}
