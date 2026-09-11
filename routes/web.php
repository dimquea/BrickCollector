<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DictionaryController;
use App\Http\Controllers\LotController;
use App\Http\Controllers\MinifiguresController;
use App\Http\Controllers\PartsController;
use App\Http\Controllers\SetsController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home', [
    // Плашка «справочник не импортирован» должна появляться по факту, а не
    // висеть всегда: на свежей установке она подсказка, на заполненной — ложь.
    'catalogItems' => (int) DB::table('bl_items')->count(),
    'entryCount' => (int) DB::table('collection_entries')->count(),
]))->name('home');

/*
 * The catalog: everything BrickLink knows about, read-only.
 */
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog');

Route::get('/catalog/{type}/{id}', [CatalogController::class, 'show'])
    ->where('type', '[A-Z]')
    ->name('catalog.show');

// Adding lives under the catalog because that is where one does it, and a
// catalog item is what the action needs.
Route::post('/catalog/{type}/{id}/add', [CatalogController::class, 'addToCollection'])
    ->where('type', '[A-Z]')
    ->name('catalog.add');


/*
 * The collection is not a section of its own: a thing is filed by what it is.
 * Sets live here, and so do gear, books and paper catalogs — they have no
 * section of their own and one owns them whole, like a set. Parts and
 * minifigures get sections of their own.
 */
Route::get('/sets', [SetsController::class, 'index'])->name('sets.index');
Route::get('/sets/{entry}', [SetsController::class, 'show'])->name('sets.show');
Route::patch('/sets/{entry}', [SetsController::class, 'update'])->name('sets.update');
Route::delete('/sets/{entry}', [SetsController::class, 'destroy'])->name('sets.destroy');

/*
 * Parts are counted, not owned in copies: the same brick sits in several
 * boxes at once, so this section lists totals per part and colour. A loose
 * lot is the exception — bought on some day, kept in some place — and has a
 * page of its own, like a standalone minifigure.
 *
 * The lot routes are declared first: /parts/copy/12 has the shape of
 * /parts/{id}/{color} and would be read as a part called "copy".
 */
Route::get('/parts', [PartsController::class, 'index'])->name('parts.index');
Route::get('/parts/copy/{entry}', [PartsController::class, 'copy'])->name('parts.copy');
Route::patch('/parts/copy/{entry}', [PartsController::class, 'update'])->name('parts.update');
Route::patch('/parts/copy/{entry}/qty', [PartsController::class, 'resize'])->name('parts.resize');
Route::delete('/parts/copy/{entry}', [PartsController::class, 'destroy'])->name('parts.destroy');
Route::get('/parts/{id}/{color}', [PartsController::class, 'show'])
    ->where('color', '[0-9]+')
    ->name('parts.show');

/*
 * Minifigures are collapsed to one entry per figure in the list, because "do I
 * have this one" is the question. A standalone copy still keeps a page of its
 * own: what was paid for it and when belongs to the copy, not to the figure.
 *
 * The copy route is declared first, or "copy" would be read as a figure id.
 */
Route::get('/minifigures', [MinifiguresController::class, 'index'])->name('minifigures.index');
Route::get('/minifigures/copy/{entry}', [MinifiguresController::class, 'copy'])->name('minifigures.copy');
Route::patch('/minifigures/copy/{entry}', [MinifiguresController::class, 'update'])->name('minifigures.update');
Route::delete('/minifigures/copy/{entry}', [MinifiguresController::class, 'destroy'])->name('minifigures.destroy');
Route::get('/minifigures/{id}', [MinifiguresController::class, 'show'])->name('minifigures.show');

// A lot belongs to a copy of anything, so it sits outside the sections.
Route::patch('/lots/{item}', [LotController::class, 'updateLost'])->name('lots.lost');

/*
 * Settings and the internal dictionaries.
 */
/*
 * Сводка по коллекции: считается на лету из тех же таблиц, что и разделы.
 */
Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

Route::post('/settings/catalog', [SettingsController::class, 'refreshCatalog'])
    ->name('settings.catalog.refresh');

Route::get('/settings/catalog', [SettingsController::class, 'catalogStatus'])
    ->name('settings.catalog.status');

Route::patch('/settings/links/{link}', [SettingsController::class, 'updateLink'])
    ->name('settings.links.update');

Route::post('/settings/dictionary/{kind}', [DictionaryController::class, 'store'])
    ->name('dictionary.store');
Route::patch('/settings/dictionary/{kind}/{id}', [DictionaryController::class, 'update'])
    ->name('dictionary.update');
Route::delete('/settings/dictionary/{kind}/{id}', [DictionaryController::class, 'destroy'])
    ->name('dictionary.destroy');

Route::post('/locale', function (Request $request) {
    $validated = $request->validate([
        'locale' => ['required', 'string', 'in:'.implode(',', SetLocale::SUPPORTED)],
    ]);

    $request->session()->put('locale', $validated['locale']);

    return back();
})->name('locale.set');
