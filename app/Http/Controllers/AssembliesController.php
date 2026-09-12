<?php

namespace App\Http\Controllers;

use App\Collection\Actions\MoveParts;
use App\Collection\Actions\UpdateEntryMeta;
use App\Collection\AssemblyImages;
use App\Collection\Models\Entry;
use App\Collection\Models\Item;
use App\Collection\Models\Source;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use App\Collection\Queries\EntryContents;
use App\Collection\Queries\LooseParts;
use App\Http\ListFilters;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assemblies: groups of loose parts a person made up.
 *
 * An entry with no item_type — the only kind with no catalogue counterpart, so
 * it carries a name of its own and, if its owner uploads one, a picture.
 *
 * Parts come from the loose pile and go back to it. Nothing is created or
 * destroyed by that, which is the whole reason an assembly is an entry rather
 * than a label stuck on lots: the part counts stay the same, only the answer
 * to "where is it" changes.
 */
class AssembliesController extends Controller
{
    public function index(Request $request, AssemblyImages $images): Response
    {
        $filters = ListFilters::read($request, ['q' => ['string', 'max:120']], switches: ['missing']);

        $rows = fn (string $column) => DB::table('collection_items')
            ->whereColumn('collection_items.entry_id', 'collection_entries.id')
            ->where('item_type', 'P')
            ->where('counts', 1)
            ->selectRaw("COALESCE(SUM({$column}), 0)");

        $query = Entry::whereNull('item_type')
            ->select('collection_entries.*')
            ->selectSub($rows('qty'), 'parts_count')
            // Сколько ещё нужно, чтобы модель была закончена — или сколько из
            // неё потерялось. Поле одно, и различить их можно только зная, что
            // это за модель.
            ->selectSub($rows('lost_qty'), 'missing_count');

        if (($filters['q'] ?? null)) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']);
            $query->where('name', 'like', "%{$escaped}%");
        }

        // Вычисляемый статус уже посчитан на записи, поэтому фильтр — это
        // условие по колонке, а не подзапрос на каждую карточку.
        if (! empty($filters['missing'])) {
            $query->where('flag_incomplete', true);
        }

        $assemblies = $query->orderBy('name')->paginate(Settings::perPage('assemblies'))->withQueryString();

        return Inertia::render('Assemblies/Index', [
            'cardSize' => Settings::cardSize('assemblies'),
            'filters' => $filters,
            'assemblies' => $assemblies->through(fn (Entry $entry) => [
                'id' => $entry->id,
                'name' => $entry->name,
                'parts' => (int) $entry->parts_count,
                'missing' => (int) $entry->missing_count,
                'has_image' => $images->has($entry->id),
                'tags' => $entry->tags()->where('show_in_list', true)->get(['ref_tags.name', 'ref_tags.color']),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $entry = Entry::create([
            'name' => $validated['name'],
            'flag_incomplete' => false,
            'flag_missing_figs' => false,
        ]);

        return to_route('assemblies.show', $entry);
    }

    public function show(Entry $entry, EntryContents $contents, AssemblyImages $images): Response
    {
        abort_unless($entry->isAssembly(), 404);

        return Inertia::render('Assemblies/Show', [
            'entry' => [
                'id' => $entry->id,
                'name' => $entry->name,
                'has_image' => $images->has($entry->id),
            ],
            // Flat: an assembly is a list of parts, not a tree. What a part is
            // made of in the catalogue travels with it but is not shown.
            'parts' => $contents->tree($entry),
            'totals' => $contents->totals($entry),
            'meta' => [
                'acquired_at' => $entry->acquired_at?->format('Y-m-d'),
                'price' => $entry->price,
                'source_id' => $entry->source_id,
                'storage_id' => $entry->storage_id,
                'note' => $entry->note,
                // No box and no instructions to have a status about.
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

    /** Name and metadata. The name is what an assembly has instead of a number. */
    public function update(Request $request, Entry $entry, UpdateEntryMeta $update): JsonResponse
    {
        abort_unless($entry->isAssembly(), 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'acquired_at' => ['nullable', 'date'],
            'price' => ['nullable', 'integer', 'min:0'],
            'source_id' => ['nullable', 'integer', 'exists:ref_sources,id'],
            'storage_id' => ['nullable', 'integer', 'exists:ref_storages,id'],
            'note' => ['nullable', 'string', 'max:5000'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'exists:ref_tags,id'],
        ]);

        if (array_key_exists('name', $validated)) {
            $entry->update(['name' => $validated['name']]);
        }

        $update->handle($entry, array_diff_key($validated, ['name' => null]));

        return response()->json(['message' => __('app.collection.saved')]);
    }

    /**
     * Deleting an assembly gives its parts back rather than eating them.
     *
     * A set deleted from the collection takes its contents with it — they were
     * the set. An assembly is the other way round: the bricks are still on the
     * table, only the idea of them being one thing is gone.
     */
    public function destroy(Entry $entry, MoveParts $move, AssemblyImages $images): RedirectResponse
    {
        abort_unless($entry->isAssembly(), 404);

        DB::transaction(function () use ($entry, $move) {
            foreach ($entry->roots()->where('item_type', 'P')->get() as $row) {
                $move->outOfAssembly($entry, $row, $row->qty);
            }

            $entry->delete();
        });

        $images->delete($entry->id);

        return to_route('assemblies.index')
            ->with('flash', ['message' => __('app.assembly.returned')]);
    }

    /** Loose parts available to build with, for the dialog. */
    public function loose(Request $request, Entry $entry, LooseParts $loose): JsonResponse
    {
        abort_unless($entry->isAssembly(), 404);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'color_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'parts' => $loose->available($filters),
            'colours' => $loose->colours(),
        ]);
    }

    public function addPart(Request $request, Entry $entry, MoveParts $move): JsonResponse
    {
        abort_unless($entry->isAssembly(), 404);

        $validated = $request->validate([
            'item_id' => ['required', 'string'],
            'color_id' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'min:1', 'max:99999'],
        ]);

        $move->intoAssembly($entry, $validated['item_id'], (int) $validated['color_id'], (int) $validated['qty']);

        return response()->json(['message' => __('app.assembly.added')]);
    }

    /**
     * Changes how many of a part the assembly holds.
     *
     * Down gives the difference back to the loose pile; up takes it from
     * there, and says so when there is not enough.
     */
    public function updatePart(Request $request, Entry $entry, Item $item, MoveParts $move): JsonResponse
    {
        abort_unless($entry->isAssembly() && $item->entry_id === $entry->id, 404);

        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:0', 'max:99999'],
        ]);

        $qty = (int) $validated['qty'];

        if ($qty < $item->qty) {
            $move->outOfAssembly($entry, $item, $item->qty - $qty);
        } elseif ($qty > $item->qty) {
            $move->intoAssembly($entry, $item->item_id, $item->color_id, $qty - $item->qty);
        }

        return response()->json(['message' => __('app.assembly.returned')]);
    }

    public function removePart(Entry $entry, Item $item, MoveParts $move): JsonResponse
    {
        abort_unless($entry->isAssembly() && $item->entry_id === $entry->id, 404);

        $move->outOfAssembly($entry, $item, $item->qty);

        return response()->json(['message' => __('app.assembly.returned')]);
    }

    public function storeImage(Request $request, Entry $entry, AssemblyImages $images): RedirectResponse
    {
        abort_unless($entry->isAssembly(), 404);

        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:'.implode(',', AssemblyImages::EXTENSIONS), 'max:4096'],
        ]);

        $images->put($entry->id, $request->file('image'));

        return back()->with('flash', ['message' => __('app.collection.saved')]);
    }

    public function destroyImage(Entry $entry, AssemblyImages $images): RedirectResponse
    {
        abort_unless($entry->isAssembly(), 404);

        $images->delete($entry->id);

        return back()->with('flash', ['message' => __('app.collection.saved')]);
    }
}
