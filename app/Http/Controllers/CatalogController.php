<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item;
use App\Catalog\Models\ItemType;
use App\Catalog\Models\Theme;
use App\Catalog\Images\ItemImages;
use App\Catalog\Queries\ItemInventory;
use App\Catalog\Queries\ItemParents;
use App\Catalog\Queries\SearchItems;
use App\Collection\Actions\AddToCollection;
use App\Collection\Actions\MoveParts;
use App\Collection\Actions\ResizeLot;
use App\Collection\Models\Entry;
use App\Collection\Queries\PartPlaces;
use Illuminate\Http\RedirectResponse;
use App\Support\ExternalLinks;
use App\Support\Settings;
use App\Http\ListFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request, SearchItems $search): Response
    {
        $filters = ListFilters::read($request, [
            'q' => ['string', 'max:120'],
            'type' => ['string', 'in:'.implode(',', ItemType::BROWSABLE)],
            'theme_id' => ['integer'],
            'year' => ['integer', 'min:1949', 'max:'.(date('Y') + 1)],
        ], switches: ['has_inventory']);

        $results = $search->filters($filters)->paginate(Settings::perPage('catalog'));

        $images = app(ItemImages::class)->availability(
            collect($results->items())
                ->map(fn (Item $item) => [$item->type, $item->id, (int) $item->image_color_id])
                ->all(),
        );

        return Inertia::render('Catalog/Index', [
            'cardSize' => Settings::cardSize('catalog'),
            'filters' => $filters,
            'results' => $results->through(fn (Item $item) => [
                'type' => $item->type,
                'id' => $item->id,
                'name' => $item->name,
                'year' => $item->year,
                'theme' => $item->theme?->path,
                'has_inventory' => $item->has_inventory,
                'image_color_id' => $item->image_color_id,
                'has_image' => $images[$item->type.'/'.$item->id.'/'.(int) $item->image_color_id] ?? false,
            ]),
            'itemTypes' => ItemType::whereIn('code', ItemType::BROWSABLE)
                ->orderByRaw("CASE code ".implode(' ', array_map(
                    fn ($code, $i) => "WHEN '{$code}' THEN {$i}",
                    ItemType::BROWSABLE,
                    array_keys(ItemType::BROWSABLE),
                ))." END")
                ->get(['code', 'name']),
            // Root themes only. The tree has 6,394 nodes; shipping even two
            // levels of it was 3,546 options in every response. Choosing a
            // root matches its whole subtree, so nothing becomes unreachable.
            'themes' => Theme::where('depth', 0)->orderBy('path')->get(['id', 'path']),
            'years' => $this->years(),
        ]);
    }

    public function show(
        Request $request,
        string $type,
        string $id,
        ItemInventory $inventory,
        ItemParents $parents,
    ): Response {
        $item = Item::with('theme', 'category')
            ->where('type', $type)
            ->where('id', $id)
            ->firstOrFail();

        $tree = $item->has_inventory ? $inventory->tree($type, $id) : [];

        // A part is added in a colour, and either as a lot of its own or onto
        // one already held, so its dialog needs both lists. Nothing else
        // has either.
        $lots = $item->type === 'P' ? PartPlaces::lots($item->id) : collect();

        return Inertia::render('Catalog/Show', [
            'links' => ExternalLinks::for($item->type, $item->id, $item->image_color_id),
            'item' => [
                'type' => $item->type,
                'id' => $item->id,
                'name' => $item->name,
                'year' => $item->year,
                'weight' => $item->weight,
                'theme' => $item->theme?->path,
                'theme_id' => $item->theme_id,
                'category' => $item->category?->name,
                'image_color_id' => $item->image_color_id,
                'has_inventory' => $item->has_inventory,
                'type_name' => ItemType::find($item->type)?->name,
            ],
            'inventory' => $tree,
            'totals' => $inventory->summarise($tree),
            'elementCodes' => $this->elementCodes($item),
            'colours' => $item->type === 'P' ? $this->colours($item, $lots) : [],
            'lots' => $lots->values(),
            // A part can also go straight into an assembly: bought for the
            // model being built, not for the drawer.
            'assemblies' => $item->type === 'P'
                ? Entry::whereNull('item_type')->orderBy('name')->get(['id', 'name'])
                : [],
            'parents' => $this->parents($request, $item, $parents),
        ]);
    }

    /**
     * What this item is part of: the kinds it turns up in, and one page of
     * one kind.
     *
     * Null when it turns up nowhere and nothing is being filtered — there is
     * no block to draw. Under a colour filter the block stays even when empty,
     * or there would be no way back to another colour.
     *
     * @return array<string, mixed>|null
     */
    private function parents(Request $request, Item $item, ItemParents $parents): ?array
    {
        $colour = null;

        if ($item->type === 'P') {
            $asked = $request->query('in_color');

            $colour = $asked === null || $asked === '' || ! ctype_digit((string) $asked)
                ? null
                : (int) $asked;
        }

        $counts = $parents->kinds($item->type, $item->id, $colour);

        if ($counts === [] && $colour === null) {
            return null;
        }

        // The kind asked for, unless it holds nothing now — a colour filter
        // can empty the tab that was open.
        $kind = $request->query('in');
        $kind = isset($counts[$kind]) ? $kind : array_key_first($counts);

        $names = ItemType::whereIn('code', array_keys($counts))->pluck('name', 'code');

        return [
            'kind' => $kind,
            'kinds' => collect($counts)
                ->map(fn (int $count, string $code) => [
                    'code' => $code,
                    'name' => $names[$code] ?? $code,
                    'count' => $count,
                ])
                ->values(),
            'colour' => $colour,
            'colours' => $item->type === 'P' ? $parents->colours($item->id) : [],
            'rows' => $kind === null
                ? []
                : $parents
                    ->page($item->type, $item->id, $kind, $colour, $counts[$kind], (int) $request->query('in_page', 1))
                    ->appends($request->except('in_page')),
        ];
    }

    /**
     * Puts a catalog item into the collection and goes to where it now lives.
     */
    public function addToCollection(
        Request $request,
        string $type,
        string $id,
        AddToCollection $add,
        ResizeLot $resize,
    ): RedirectResponse {
        $item = Item::where('type', $type)->where('id', $id)->firstOrFail();

        $validated = $request->validate([
            'qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'color_id' => ['nullable', 'integer', 'exists:bl_colors,id'],
            'lot_id' => ['nullable', 'integer'],
            'assembly_id' => ['nullable', 'integer'],
        ]);

        $qty = $validated['qty'] ?? 1;

        if ($item->type === 'P') {
            return $this->addPart(
                $item,
                $qty,
                $validated['color_id'] ?? (int) $item->image_color_id,
                $validated['lot_id'] ?? null,
                $validated['assembly_id'] ?? null,
                $add,
                $resize,
            );
        }

        $entry = $add->handle($item, ['qty' => $qty]);

        $flash = ['message' => __('app.collection.added', ['name' => $item->name])];

        // Each kind of thing has a place it now lives; go there rather than
        // leaving the person on the catalog page wondering where it went.
        $destination = match (true) {
            in_array($item->type, SetsController::TYPES, true) => to_route('sets.show', $entry),
            $item->type === 'M' => to_route('minifigures.copy', $entry),
            default => back(),
        };

        return $destination->with('flash', $flash);
    }

    /**
     * A part goes either into a lot of its own or onto one already held.
     *
     * Topping up is for more of the same; a new lot is another purchase, with
     * its own date, price and place. Only the person knows which it is, so the
     * dialog asks rather than the server guessing.
     */
    private function addPart(
        Item $item,
        int $qty,
        int $colorId,
        ?int $lotId,
        ?int $assemblyId,
        AddToCollection $add,
        ResizeLot $resize,
    ): RedirectResponse {
        if ($assemblyId !== null) {
            return $this->addPartToAssembly($item, $qty, $colorId, $assemblyId, $add);
        }

        if ($lotId === null) {
            $entry = $add->handle($item, ['qty' => $qty, 'color_id' => $colorId]);

            return to_route('parts.copy', $entry)
                ->with('flash', ['message' => __('app.collection.added', ['name' => $item->name])]);
        }

        // Only a lot of this very part in this very colour: topping up a red
        // brick with blue ones would quietly change what the lot is.
        $lot = Entry::where('id', $lotId)
            ->where('item_type', 'P')
            ->where('item_id', $item->id)
            ->where('color_id', $colorId)
            ->first();

        if ($lot === null) {
            throw ValidationException::withMessages(['lot_id' => __('app.parts.lot_mismatch')]);
        }

        $resize->handle($lot, (int) $lot->roots()->value('qty') + $qty);

        return to_route('parts.copy', $lot)
            ->with('flash', ['message' => __('app.parts.topped_up', ['count' => $qty])]);
    }

    /**
     * A part bought for something being built goes into the assembly directly.
     *
     * It is still entered as a lot first and then moved, rather than written
     * into the assembly by hand: that is the one path parts take into an
     * assembly, and a second one would be a second set of rules about what
     * happens to the rows underneath.
     */
    private function addPartToAssembly(
        Item $item,
        int $qty,
        int $colorId,
        int $assemblyId,
        AddToCollection $add,
    ): RedirectResponse {
        $assembly = Entry::whereNull('item_type')->find($assemblyId);

        if ($assembly === null) {
            throw ValidationException::withMessages(['assembly_id' => __('app.assembly.gone')]);
        }

        $lot = $add->handle($item, ['qty' => $qty, 'color_id' => $colorId]);

        app(MoveParts::class)->fromLot($assembly, $lot, $qty);

        return to_route('assemblies.show', $assembly)
            ->with('flash', ['message' => __('app.assembly.added')]);
    }

    /**
     * Colours a part can be added in.
     *
     * The ones BrickLink has element codes for, plus any already held and the
     * one in the picture. A part with no element codes at all — old moulds,
     * mostly — gets the whole palette: offering only its picture colour would
     * leave every other one impossible to record.
     *
     * @param  Collection<int, array<string, mixed>>  $lots
     * @return array<int, array<string, mixed>>
     */
    private function colours(Item $item, Collection $lots): array
    {
        $known = DB::table('bl_element_codes')
            ->where('item_type', 'P')
            ->where('item_id', $item->id)
            ->distinct()
            ->pluck('color_id');

        $query = DB::table('bl_colors')->orderBy('name');

        if ($known->isNotEmpty()) {
            $query->whereIn('id', $known
                ->merge($lots->pluck('color_id'))
                ->push((int) $item->image_color_id)
                ->unique()
                ->values());
        }

        return $query->get(['id', 'name', 'rgb'])->map(fn ($row) => (array) $row)->all();
    }

    /**
     * LEGO element ids for a part, which is how a number printed in a set's
     * instructions can be looked up.
     *
     * @return array<int, array<string, mixed>>
     */
    private function elementCodes(Item $item): array
    {
        if ($item->type !== 'P') {
            return [];
        }

        return DB::table('bl_element_codes as e')
            ->leftJoin('bl_colors as c', 'c.id', '=', 'e.color_id')
            ->where('e.item_type', $item->type)
            ->where('e.item_id', $item->id)
            ->orderBy('c.name')
            ->limit(200)
            ->get(['e.code', 'e.color_id', 'c.name as color_name', 'c.rgb as color_rgb'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return int[] */
    private function years(): array
    {
        return range((int) date('Y') + 1, 1949);
    }
}
