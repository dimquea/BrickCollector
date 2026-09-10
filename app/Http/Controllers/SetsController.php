<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item as CatalogItem;
use App\Collection\Actions\UpdateEntryMeta;
use App\Collection\Models\Entry;
use App\Collection\Models\Source;
use App\Collection\Models\Status;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use App\Collection\Queries\EntryContents;
use App\Collection\Queries\EntryFacets;
use App\Collection\Queries\SearchEntries;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owned sets, and everything else that is neither a part nor a minifigure.
 *
 * Gear, books and paper catalogs have no section of their own and are things
 * one owns as a whole, like a set, so they live here rather than nowhere.
 */
class SetsController extends Controller
{
    /** Everything except parts and minifigures, which have their own sections. */
    public const TYPES = ['S', 'G', 'B', 'C'];

    public function index(Request $request, SearchEntries $search): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:'.implode(',', self::TYPES)],
            'theme_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'min:1949', 'max:'.(date('Y') + 1)],
            'status_id' => ['nullable', 'integer'],
            'tag_id' => ['nullable', 'integer'],
            'incomplete' => ['nullable', 'boolean'],
            'missing_figs' => ['nullable', 'boolean'],
        ]);

        $entries = $search->filters($filters)->ofTypes(self::TYPES)->paginate();

        // Type, theme and year come from what is owned, not from the catalog:
        // a filter that can only return nothing is worse than no filter.
        $facets = (new EntryFacets(self::TYPES))->all();

        return Inertia::render('Sets/Index', [
            'filters' => $filters,
            'entries' => $entries->through(fn (Entry $entry) => $this->card($entry)),
            'itemTypes' => $facets['types'],
            'themes' => $facets['themes'],
            'years' => $facets['years'],
            'statuses' => $this->statuses(),
            'tags' => Tag::orderBy('sort')->get(['id', 'name', 'color']),
        ]);
    }

    public function show(Entry $entry, EntryContents $contents): Response
    {
        $catalogItem = CatalogItem::with('theme')
            ->where('type', $entry->item_type)
            ->where('id', $entry->item_id)
            ->first();

        return Inertia::render('Sets/Show', [
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
                'status_codes' => $entry->statuses()->pluck('ref_statuses.code')->filter()->values(),
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
                'statuses' => $this->statuses(),
            ],
            'currency' => Settings::currency(),
            'contents' => $contents->tree($entry),
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

    public function destroy(Entry $entry): RedirectResponse
    {
        // The tree goes with it: collection_items cascades on delete.
        $entry->delete();

        return to_route('sets.index')->with('flash', ['message' => __('app.collection.removed')]);
    }

    /**
     * A seeded status shows a translated label, so the language of the
     * interface wins over the language of whoever installed the service.
     *
     * @return array<int, array<string, mixed>>
     */
    private function statuses(): array
    {
        return Status::orderBy('sort')->get()->map(fn (Status $status) => [
            'id' => $status->id,
            'code' => $status->code,
            'name' => $status->code ? __('app.status.'.$status->code) : $status->name,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function card(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->item_type,
            'item_id' => $entry->item_id,
            'name' => $entry->item_name ?? $entry->name ?? $entry->item_id,
            'year' => $entry->year,
            'theme' => $entry->theme,
            'image_color_id' => (int) ($entry->image_color_id ?? 0),
            'flag_incomplete' => $entry->flag_incomplete,
            'flag_missing_figs' => $entry->flag_missing_figs,
            // Codes rather than ids: the card shows an icon per known status,
            // and an icon has to be chosen by meaning, not by row number.
            'status_codes' => $entry->statuses()->pluck('ref_statuses.code')->filter()->values(),
            'tags' => $entry->tags()->where('show_in_list', true)->get(['ref_tags.name', 'ref_tags.color']),
        ];
    }
}
