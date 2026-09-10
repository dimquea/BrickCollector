<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item;
use App\Catalog\Models\ItemType;
use App\Catalog\Models\Theme;
use App\Catalog\Images\ItemImages;
use App\Catalog\Queries\ItemInventory;
use App\Catalog\Queries\SearchItems;
use App\Collection\Actions\AddToCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request, SearchItems $search): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:'.implode(',', ItemType::BROWSABLE)],
            'theme_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'min:1949', 'max:'.(date('Y') + 1)],
            'has_inventory' => ['nullable', 'boolean'],
        ]);

        $results = $search->filters($filters)->paginate();

        $images = app(ItemImages::class)->availability(
            collect($results->items())
                ->map(fn (Item $item) => [$item->type, $item->id, (int) $item->image_color_id])
                ->all(),
        );

        return Inertia::render('Catalog/Index', [
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

    public function show(string $type, string $id, ItemInventory $inventory): Response
    {
        $item = Item::with('theme', 'category')
            ->where('type', $type)
            ->where('id', $id)
            ->firstOrFail();

        $tree = $item->has_inventory ? $inventory->tree($type, $id) : [];

        return Inertia::render('Catalog/Show', [
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
        ]);
    }

    /**
     * Puts a catalog item into the collection and goes to where it now lives.
     *
     * Parts and minifigures have no section yet, so those land back on the
     * catalog page with a note. Sending them to a page that does not exist
     * would be worse than saying so.
     */
    public function addToCollection(
        Request $request,
        string $type,
        string $id,
        AddToCollection $add,
    ): RedirectResponse {
        $item = Item::where('type', $type)->where('id', $id)->firstOrFail();

        $validated = $request->validate([
            'qty' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $entry = $add->handle($item, ['qty' => $validated['qty'] ?? 1]);

        $flash = ['message' => __('app.collection.added', ['name' => $item->name])];

        return in_array($item->type, SetsController::TYPES, true)
            ? to_route('sets.show', $entry)->with('flash', $flash)
            : back()->with('flash', $flash);
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
