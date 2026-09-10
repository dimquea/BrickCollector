<?php

namespace App\Http\Controllers;

use App\Catalog\Images\ItemImages;
use App\Catalog\Models\Item;
use Symfony\Component\HttpFoundation\Response;

class ItemImageController extends Controller
{
    public function show(string $type, string $id, int $color, ItemImages $images): Response
    {
        $item = Item::where('type', $type)->where('id', $id)->first();

        if ($item) {
            $path = $images->cached($item, $color);

            if ($path !== null && ($contents = $images->contents($path)) !== null) {
                return response($contents, 200, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=31536000, immutable',
                ]);
            }
        }

        // A placeholder is a normal answer, not an error: plenty of catalog
        // entries simply have no picture. Cached briefly so a later import or
        // a successful retry can replace it.
        return response($images->placeholder($type), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
