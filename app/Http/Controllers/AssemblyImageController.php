<?php

namespace App\Http\Controllers;

use App\Collection\AssemblyImages;
use Symfony\Component\HttpFoundation\Response;

class AssemblyImageController extends Controller
{
    public function show(int $entry, AssemblyImages $images): Response
    {
        $path = $images->path($entry);
        $contents = $path === null ? null : $images->contents($path);

        if ($contents === null) {
            // An assembly without a picture is the normal case, not an error:
            // the page draws a placeholder and asks for nothing.
            return response()->noContent(404);
        }

        return response($contents, 200, [
            'Content-Type' => $images->mime($path),
            // The file is replaced in place, so it may not be cached for long.
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
