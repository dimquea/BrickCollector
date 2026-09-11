<?php

namespace App\Http\Controllers;

use App\Catalog\Import\CatalogRefresh;
use App\Catalog\Import\CatalogStatus;
use App\Collection\Models\Link;
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
            'links' => Link::orderBy('sort')->get()->map(fn (Link $link) => [
                'id' => $link->id,
                'code' => $link->code,
                'enabled' => $link->enabled,
                'label' => $link->label,
                'url_set' => $link->url_set,
                'url_minifig' => $link->url_minifig,
                'url_part' => $link->url_part,
                // Инструкция бывает только у набора, поэтому у неё одно поле.
                'set_only' => $link->isSetOnly(),
            ]),
            'currency' => Settings::currency(),
            'appearance' => [
                'lists' => collect(config('brickcollector.lists'))
                    ->map(fn (array $list, string $key) => [
                        'key' => $key,
                        'per_page' => Settings::perPage($key),
                        // У деталей список — таблица, размер карточки к ней не
                        // применим.
                        'cards' => $list['cards'],
                        'card_size' => Settings::cardSize($key),
                    ])
                    ->values(),
            ],
            'catalog' => $this->catalog(),
        ]);
    }

    /**
     * Запускает обновление справочника.
     *
     * Возвращается сразу: работа идёт отдельным процессом, а страница потом
     * спрашивает о ней сама.
     */
    public function refreshCatalog(CatalogRefresh $refresh): JsonResponse
    {
        if (CatalogStatus::isRunning()) {
            return response()->json($this->catalog() + ['message' => __('app.settings.catalog_running')]);
        }

        $refresh->start();

        return response()->json($this->catalog() + ['message' => __('app.settings.catalog_started')]);
    }

    /** Как там справочник: страница спрашивает, пока идёт импорт. */
    public function catalogStatus(): JsonResponse
    {
        return response()->json($this->catalog());
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        $status = CatalogStatus::current();

        return [
            'imported_at' => $status['imported_at'],
            'items' => \App\Catalog\Models\Item::count(),
            'status' => $status,
        ];
    }

    public function update(Request $request): JsonResponse
    {
        $lists = implode(',', array_keys(config('brickcollector.lists')));

        $validated = $request->validate([
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'locale' => ['nullable', 'string', 'in:'.implode(',', SetLocale::SUPPORTED)],
            'per_page' => ['array'],
            'per_page.*' => ['integer', 'min:6', 'max:200'],
            'card_size' => ['array'],
            'card_size.*.desktop' => ['in:large,small'],
            'card_size.*.mobile' => ['in:large,small'],
        ]);

        foreach ($validated['per_page'] ?? [] as $list => $value) {
            if (str_contains($lists, $list)) {
                Settings::put("per_page.{$list}", (string) $value);
            }
        }

        foreach ($validated['card_size'] ?? [] as $list => $sizes) {
            foreach ($sizes as $screen => $size) {
                if (str_contains($lists, $list)) {
                    Settings::put("card_size.{$list}.{$screen}", $size);
                }
            }
        }

        if (array_key_exists('currency', $validated)) {
            Settings::put('currency', strtoupper($validated['currency']));
        }

        if (! empty($validated['locale'])) {
            $request->session()->put('locale', $validated['locale']);
        }

        return response()->json(['message' => __('app.settings.saved')]);
    }

    /**
     * Настройка одного блока ссылок.
     *
     * Блоки фиксированы: создания и удаления здесь нет, только правка. Паттерн
     * проверяем на схему, а не правилом url — подстановки вида {id} делают
     * адрес невалидным до подстановки, и это нормально.
     */
    public function updateLink(Request $request, Link $link): JsonResponse
    {
        $pattern = ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/\S+$/'];

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'label' => ['nullable', 'string', 'max:60'],
            'url_set' => $pattern,
            'url_minifig' => $pattern,
            'url_part' => $pattern,
        ]);

        if ($link->isSetOnly()) {
            $validated['url_minifig'] = null;
            $validated['url_part'] = null;
        }

        $link->fill($validated)->save();

        return response()->json(['message' => __('app.settings.saved')]);
    }
}
