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

        // Нет в кэше — отправляем браузер прямо к источнику, вместо того чтобы
        // качать байты через себя. Ждать очередь не приходится: картинка
        // появляется сразу, а место на диске занимает только то, чем человек
        // владеет.
        if ($item) {
            return redirect()->away($item->imageUrl($color), 302, [
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        // Такого предмета нет в справочнике: показывать нечего и спрашивать
        // не у кого.
        return response($images->placeholder($type), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
