<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item;
use App\Catalog\Models\ItemType;
use App\Catalog\Models\Theme;
use App\Catalog\Queries\SearchItems;
use Illuminate\Http\Request;
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

    /** @return int[] */
    private function years(): array
    {
        return range((int) date('Y') + 1, 1949);
    }
}
