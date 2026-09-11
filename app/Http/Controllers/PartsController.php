<?php

namespace App\Http\Controllers;

use App\Collection\Queries\PartPlaces;
use App\Collection\Queries\PartTotals;
use App\Support\ExternalLinks;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parts held in the collection.
 *
 * Unlike sets, a part is not something one owns a copy of: the same brick is
 * spread across boxes, minifigures and a loose pile, and it is counted rather
 * than listed. So there is no entry page here and no metadata to keep — a
 * brick has no purchase date of its own.
 */
class PartsController extends Controller
{
    public function index(Request $request, PartTotals $totals): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'color_id' => ['nullable', 'integer'],
            'placement' => ['nullable', 'string', 'in:set,minifigure,loose,lost'],
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
            'inMinifigures' => $places->inMinifigures(),
            'otherColours' => $totals->otherColours($id, $color),
            'missingIn' => $places->missingIn(),
        ]);
    }
}
