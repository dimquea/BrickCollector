<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item as CatalogItem;
use App\Catalog\Models\ItemType;
use App\Collection\Actions\AddToCollection;
use App\Collection\Actions\SetLostQuantity;
use App\Collection\Actions\UpdateEntryMeta;
use App\Collection\Models\Item as CollectionItem;
use App\Collection\Models\Source;
use App\Collection\Models\Status;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use App\Collection\Queries\EntryContents;
use App\Support\Settings;
use App\Collection\Models\Entry;
use Illuminate\Http\JsonResponse;
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

    public function show(Entry $entry, EntryContents $contents): Response
    {
        $catalogItem = $entry->item_type === null ? null : CatalogItem::with('theme')
            ->where('type', $entry->item_type)
            ->where('id', $entry->item_id)
            ->first();

        return Inertia::render('Collection/Show', [
            'entry' => [
                'id' => $entry->id,
                'type' => $entry->item_type,
                'item_id' => $entry->item_id,
                'name' => $catalogItem->name ?? $entry->name ?? $entry->item_id,
                'year' => $catalogItem->year ?? null,
                'theme' => $catalogItem?->theme?->path,
                'image_color_id' => (int) ($catalogItem->image_color_id ?? 0),
                'flag_incomplete' => $entry->flag_incomplete,
                'flag_missing_figs' => $entry->flag_missing_figs,
            ],
            'meta' => [
                'acquired_at' => $entry->acquired_at?->format('Y-m-d'),
                'price' => $entry->price,
                'source_id' => $entry->source_id,
                'storage_id' => $entry->storage_id,
                'note' => $entry->note,
                'status_ids' => $entry->statuses()->pluck('ref_statuses.id')->all(),
                'tag_ids' => $entry->tags()->pluck('ref_tags.id')->all(),
            ],
            'dictionaries' => [
                'sources' => Source::where('is_active', true)->orderBy('sort')->get(['id', 'name']),
                'storages' => Storage::where('is_active', true)->orderBy('sort')->get(['id', 'name']),
                'tags' => Tag::orderBy('sort')->get(['id', 'name', 'color']),
                // A seeded status shows a translated label: its name column is
                // only a fallback and would otherwise freeze the language the
                // installation was set up in.
                'statuses' => Status::orderBy('sort')->get(['id', 'name', 'code'])
                    ->map(fn (Status $status) => [
                        'id' => $status->id,
                        'name' => $status->code
                            ? __('app.status.'.$status->code)
                            : $status->name,
                    ]),
            ],
            'currency' => Settings::currency(),
            'contents' => $contents->tree($entry),
            'totals' => $contents->totals($entry),
        ]);
    }

    public function update(Request $request, Entry $entry, UpdateEntryMeta $update): JsonResponse
    {
        $validated = $request->validate([
            'acquired_at' => ['nullable', 'date'],
            // Minor units, so an integer. The interface converts.
            'price' => ['nullable', 'integer', 'min:0'],
            'source_id' => ['nullable', 'integer', 'exists:ref_sources,id'],
            'storage_id' => ['nullable', 'integer', 'exists:ref_storages,id'],
            'note' => ['nullable', 'string', 'max:5000'],
            'status_ids' => ['array'],
            'status_ids.*' => ['integer', 'exists:ref_statuses,id'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'exists:ref_tags,id'],
        ]);

        $update->handle($entry, $validated);

        return response()->json(['message' => __('app.collection.saved')]);
    }

    /**
     * Answers JSON, not a redirect.
     *
     * This is not an API layer: it is the same web route, replying with data
     * because the caller asked for data. Inertia re-renders the page on every
     * response, and re-rendering collapses every expanded accordion — which is
     * exactly where these fields live.
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

        return to_route('collection.show', $entry)->with('flash', [
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
