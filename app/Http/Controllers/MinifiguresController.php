<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item as CatalogItem;
use App\Collection\Actions\UpdateEntryMeta;
use App\Collection\Models\Entry;
use App\Collection\Models\Source;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use App\Collection\Queries\EntryContents;
use App\Collection\Queries\MinifigurePlaces;
use App\Collection\Queries\MinifigureTotals;
use App\Support\ExternalLinks;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Http\ListFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Minifigures held in the collection.
 *
 * A figure turns up two ways — built into a set, or owned on its own — and the
 * list collapses both into one card per figure, because "do I have this one"
 * is the question being asked. Metadata still belongs to a copy, not to the
 * figure: two of the same figure can be bought on different days for different
 * money, so a standalone copy keeps a page of its own.
 */
class MinifiguresController extends Controller
{
    public function index(Request $request, MinifigureTotals $totals): Response
    {
        $filters = ListFilters::read($request, [
            'q' => ['string', 'max:120'],
            'theme_id' => ['integer'],
            'year' => ['integer', 'min:1949', 'max:'.(date('Y') + 1)],
            'tag_id' => ['integer'],
            'placement' => ['string', 'in:set,loose'],
        ]);

        $facets = $totals->facets();

        return Inertia::render('Minifigures/Index', [
            'cardSize' => Settings::cardSize('minifigures'),
            'filters' => $filters,
            'figures' => $totals->filters($filters)->paginate(Settings::perPage('minifigures')),
            'themes' => $facets['themes'],
            'years' => $facets['years'],
            'tags' => Tag::orderBy('sort')->get(['id', 'name', 'color']),
        ]);
    }

    public function show(string $id, MinifigureTotals $totals): Response
    {
        $figure = $totals->one($id);

        abort_if($figure === null, 404);

        $places = new MinifigurePlaces($id);

        return Inertia::render('Minifigures/Show', [
            'links' => ExternalLinks::for('M', $id),
            'figure' => $figure,
            'parts' => $places->parts(),
            'inEntries' => $places->inEntries(),
            'missingIn' => $places->missingIn(),
            'copies' => $this->copies($id),
        ]);
    }

    /**
     * One standalone copy: what it is made of, what is missing from it, and
     * everything the user knows about it.
     */
    public function copy(Entry $entry, EntryContents $contents): Response
    {
        abort_if($entry->item_type !== 'M', 404);

        $catalogItem = CatalogItem::with('theme')
            ->where('type', 'M')
            ->where('id', $entry->item_id)
            ->first();

        // The tree of an M entry starts with a row for the figure itself. Its
        // children are what the page shows: rendering the root as a group
        // would have the figure contain itself.
        $root = collect($contents->tree($entry))->first();

        return Inertia::render('Minifigures/Copy', [
            'links' => ExternalLinks::for('M', $entry->item_id),
            'entry' => [
                'id' => $entry->id,
                'item_id' => $entry->item_id,
                'name' => $catalogItem->name ?? $entry->item_id,
                'year' => $catalogItem->year ?? null,
                'theme' => $catalogItem?->theme?->path,
                'image_color_id' => (int) ($catalogItem->image_color_id ?? 0),
            ],
            'parts' => $root['children'] ?? [],
            'meta' => [
                'acquired_at' => $entry->acquired_at?->format('Y-m-d'),
                'price' => $entry->price,
                'source_id' => $entry->source_id,
                'storage_id' => $entry->storage_id,
                'note' => $entry->note,
                // A figure has no box and no instructions, so the status
                // dictionary is not offered here; tags are.
                'status_ids' => [],
                'tag_ids' => $entry->tags()->pluck('ref_tags.id')->all(),
            ],
            'dictionaries' => [
                'sources' => Source::where('is_active', true)->orderBy('sort')->get(['id', 'name']),
                'storages' => Storage::where('is_active', true)->orderBy('sort')->get(['id', 'name']),
                'tags' => Tag::orderBy('sort')->get(['id', 'name', 'color']),
                'statuses' => [],
            ],
            'currency' => Settings::currency(),
        ]);
    }

    public function update(Request $request, Entry $entry, UpdateEntryMeta $update): JsonResponse
    {
        abort_if($entry->item_type !== 'M', 404);

        $validated = $request->validate([
            'acquired_at' => ['nullable', 'date'],
            'price' => ['nullable', 'integer', 'min:0'],
            'source_id' => ['nullable', 'integer', 'exists:ref_sources,id'],
            'storage_id' => ['nullable', 'integer', 'exists:ref_storages,id'],
            'note' => ['nullable', 'string', 'max:5000'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'exists:ref_tags,id'],
        ]);

        $update->handle($entry, $validated);

        return response()->json(['message' => __('app.collection.saved')]);
    }

    public function destroy(Entry $entry): RedirectResponse
    {
        abort_if($entry->item_type !== 'M', 404);

        $itemId = $entry->item_id;
        $entry->delete();

        return to_route('minifigures.show', $itemId)
            ->with('flash', ['message' => __('app.collection.removed')]);
    }

    /** @return array<int, array<string, mixed>> */
    private function copies(string $itemId): array
    {
        return Entry::where('item_type', 'M')
            ->where('item_id', $itemId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Entry $entry) => [
                'entry_id' => $entry->id,
                'acquired_at' => $entry->acquired_at?->format('Y-m-d'),
                'price' => $entry->price,
                'note' => $entry->note,
                'tags' => $entry->tags()->get(['ref_tags.name', 'ref_tags.color']),
            ])
            ->all();
    }
}
