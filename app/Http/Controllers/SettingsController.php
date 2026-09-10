<?php

namespace App\Http\Controllers;

use App\Collection\Models\Source;
use App\Collection\Models\Status;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use App\Http\Middleware\SetLocale;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'dictionaries' => [
                'sources' => Source::orderBy('sort')->get(),
                'storages' => Storage::orderBy('sort')->get(),
                'tags' => Tag::orderBy('sort')->get(),
                // The seeded ones show a translated label; is_system tells the
                // interface not to offer deletion.
                'statuses' => Status::orderBy('sort')->get()->map(fn (Status $status) => [
                    'id' => $status->id,
                    'name' => $status->code ? __('app.status.'.$status->code) : $status->name,
                    'code' => $status->code,
                    'is_system' => $status->is_system,
                ]),
            ],
            'colors' => DictionaryController::COLORS,
            'currency' => Settings::currency(),
            'catalog' => [
                'imported_at' => Settings::get('catalog_imported_at'),
                'items' => \App\Catalog\Models\Item::count(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'locale' => ['nullable', 'string', 'in:'.implode(',', SetLocale::SUPPORTED)],
        ]);

        if (array_key_exists('currency', $validated)) {
            Settings::put('currency', strtoupper($validated['currency']));
        }

        if (! empty($validated['locale'])) {
            $request->session()->put('locale', $validated['locale']);
        }

        return response()->json(['message' => __('app.settings.saved')]);
    }
}
