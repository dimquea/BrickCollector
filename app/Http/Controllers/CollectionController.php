<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item as CatalogItem;
use App\Catalog\Models\ItemType;
use App\Collection\Actions\AddToCollection;
use App\Collection\Models\Entry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CollectionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', ItemType::BROWSABLE)],
        ]);

        $entries = Entry::query()
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('item_type', $type))
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'collection_entries.item_type')
                ->on('i.id', '=', 'collection_entries.item_id'))
            ->leftJoin('bl_themes as t', 't.id', '=', 'i.theme_id')
            ->select([
                'collection_entries.*',
                'i.name as item_name',
                'i.image_color_id',
                'i.year',
                't.path as theme',
            ])
            ->orderByDesc('collection_entries.id')
            ->paginate(24)
            ->withQueryString();

        $totals = $this->totals();

        return Inertia::render('Collection/Index', [
            'filters' => $filters,
            'entries' => $entries->through(fn (Entry $entry) => [
                'id' => $entry->id,
                'type' => $entry->item_type,
                'item_id' => $entry->item_id,
                'name' => $entry->item_name ?? $entry->name ?? $entry->item_id,
                'year' => $entry->year,
                'theme' => $entry->theme,
                'image_color_id' => (int) ($entry->image_color_id ?? 0),
                'counts' => $this->entryTotals($entry->id),
            ]),
            'totals' => $totals,
            'itemTypes' => ItemType::whereIn('code', ItemType::BROWSABLE)->get(['code', 'name']),
        ]);
    }

    public function store(Request $request, AddToCollection $add): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', ItemType::BROWSABLE)],
            'id' => ['required', 'string'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $item = CatalogItem::where('type', $validated['type'])
            ->where('id', $validated['id'])
            ->firstOrFail();

        $entry = $add->handle($item, ['qty' => $validated['qty'] ?? 1]);

        return to_route('collection.index')->with('flash', [
            'message' => __('app.collection.added', ['name' => $item->name]),
            'entry_id' => $entry->id,
        ]);
    }

    public function destroy(Entry $entry): RedirectResponse
    {
        // The tree goes with it: collection_items cascades on delete.
        $entry->delete();

        return back()->with('flash', ['message' => __('app.collection.removed')]);
    }

    /** @return array<string, int> */
    private function totals(): array
    {
        $row = DB::table('collection_items')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN item_type = 'P' AND counts = 1 THEN qty END), 0) as parts,
                COALESCE(SUM(CASE WHEN item_type = 'M' AND counts = 1 THEN qty END), 0) as minifigures,
                COALESCE(SUM(CASE WHEN counts = 1 THEN lost_qty END), 0) as lost
            ")
            ->first();

        return [
            'entries' => Entry::count(),
            'sets' => Entry::where('item_type', 'S')->count(),
            'parts' => (int) $row->parts,
            'minifigures' => (int) $row->minifigures,
            'lost' => (int) $row->lost,
        ];
    }

    /** @return array<string, int> */
    private function entryTotals(int $entryId): array
    {
        $row = DB::table('collection_items')
            ->where('entry_id', $entryId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN item_type = 'P' AND counts = 1 THEN qty END), 0) as parts,
                COALESCE(SUM(CASE WHEN item_type = 'M' AND counts = 1 THEN qty END), 0) as minifigures
            ")
            ->first();

        return ['parts' => (int) $row->parts, 'minifigures' => (int) $row->minifigures];
    }
}
