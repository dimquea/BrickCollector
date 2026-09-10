<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DictionaryController;
use App\Http\Controllers\ItemImageController;
use App\Http\Controllers\LotController;
use App\Http\Controllers\SetsController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home'))->name('home');

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

// Item ids contain dots and other odd characters, so the id segment takes
// anything but a slash.
Route::get('/images/{type}/{id}/{color}', [ItemImageController::class, 'show'])
    ->where('type', '[A-Z]')
    ->where('color', '[0-9]+')
    ->name('item.image');

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

// A lot belongs to a copy of anything, so it sits outside the sections.
Route::patch('/lots/{item}', [LotController::class, 'updateLost'])->name('lots.lost');

/*
 * Settings and the internal dictionaries.
 */
Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

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
