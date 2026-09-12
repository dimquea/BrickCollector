<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item as CatalogItem;
use App\Collection\Actions\ResizeLot;
use App\Collection\Actions\UpdateEntryMeta;
use App\Collection\Models\Entry;
use App\Collection\Models\Source;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use App\Collection\Queries\EntryContents;
use App\Collection\Queries\PartPlaces;
use App\Collection\Queries\PartTotals;
use App\Support\ExternalLinks;
use App\Support\Settings;
use App\Http\ListFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parts held in the collection.
 *
 * Unlike sets, a part is not something one owns a copy of: the same brick is
 * spread across boxes, minifigures and a loose pile, and it is counted rather
 * than listed. The loose pile is the exception. A lot of loose parts was
 * bought on some day for some money and sits in some drawer, so it keeps a
 * page of its own, the way a standalone minifigure does.
 */
class PartsController extends Controller
{
    public function index(Request $request, PartTotals $totals): Response
    {
        $filters = ListFilters::read($request, [
            'q' => ['string', 'max:120'],
            'color_id' => ['integer'],
            'placement' => ['string', 'in:set,minifigure,loose,assembly,lost'],
        ]);

        return Inertia::render('Parts/Index', [
            'filters' => $filters,
            'parts' => $totals->filters($filters)->paginate(Settings::perPage('parts')),
            'colours' => $totals->colours(),
        ]);
    }

    public function show(string $id, int $color, PartTotals $totals): Response
    {
        $part = $totals->one($id, $color);

        abort_if($part === null, 404);

        $places = new PartPlaces($id, $color);

        return Inertia::render('Parts/Show', [
            'links' => ExternalLinks::for('P', $id, $color),
            'part' => $part,
            'inEntries' => $places->inEntries(),
            'loose' => $places->loose(),
            'assemblies' => $places->assemblies(),
            'inMinifigures' => $places->inMinifigures(),
            'otherColours' => $totals->otherColours($id, $color),
            'missingIn' => $places->missingIn(),
        ]);
    }

    /** One loose lot: how many, in what colour, and what is known about it. */
    public function copy(Entry $entry, EntryContents $contents): Response
    {
        abort_if($entry->item_type !== 'P', 404);

        $catalogItem = CatalogItem::where('type', 'P')->where('id', $entry->item_id)->first();
        $colour = DB::table('bl_colors')->where('id', $entry->color_id)->first(['name', 'rgb']);
        $lot = $entry->roots()->first();

        // The tree of a P entry starts with a row for the part itself; what
        // the page lists is underneath it — the rail and sleepers of a length
        // of track. For a plain brick that is nothing.
        $root = collect($contents->tree($entry))->first();

        return Inertia::render('Parts/Copy', [
            'links' => ExternalLinks::for('P', $entry->item_id, $entry->color_id),
            'entry' => [
                'id' => $entry->id,
                'item_id' => $entry->item_id,
                'name' => $catalogItem->name ?? $entry->item_id,
                'color_id' => (int) $entry->color_id,
                'color_name' => $colour?->name,
                'color_rgb' => $colour?->rgb,
                'qty' => (int) ($lot->qty ?? 0),
                'lost' => (int) ($lot->lost_qty ?? 0),
            ],
            'parts' => $root['children'] ?? [],
            'meta' => [
                'acquired_at' => $entry->acquired_at?->format('Y-m-d'),
                'price' => $entry->price,
                'source_id' => $entry->source_id,
                'storage_id' => $entry->storage_id,
                'note' => $entry->note,
                // A loose part has no box and no instructions, so the status
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
        abort_if($entry->item_type !== 'P', 404);

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

    public function resize(Request $request, Entry $entry, ResizeLot $resize): JsonResponse
    {
        abort_if($entry->item_type !== 'P', 404);

        // Not below what is marked missing: the loss would outnumber the lot.
        $lost = (int) $entry->roots()->value('lost_qty');

        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:'.max(1, $lost), 'max:99999'],
        ]);

        $resize->handle($entry, $validated['qty']);

        return response()->json([
            'message' => __('app.collection.saved'),
            'qty' => $validated['qty'],
        ]);
    }

    public function destroy(Entry $entry, PartTotals $totals): RedirectResponse
    {
        abort_if($entry->item_type !== 'P', 404);

        [$itemId, $colorId] = [$entry->item_id, (int) $entry->color_id];

        $entry->delete();

        // Back to the part, unless that was the last of it and its page is
        // now a 404.
        $destination = $totals->one($itemId, $colorId) !== null
            ? to_route('parts.show', [$itemId, $colorId])
            : to_route('parts.index');

        return $destination->with('flash', ['message' => __('app.collection.removed')]);
    }
}
