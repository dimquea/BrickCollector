<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\ItemImageController;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home'))->name('home');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog');

Route::get('/catalog/{type}/{id}', [CatalogController::class, 'show'])
    ->where('type', '[A-Z]')
    ->name('catalog.show');

// Item ids contain dots and slashes, so the id segment takes anything but a slash.
Route::get('/images/{type}/{id}/{color}', [ItemImageController::class, 'show'])
    ->where('type', '[A-Z]')
    ->where('color', '[0-9]+')
    ->name('item.image');

Route::get('/collection', [CollectionController::class, 'index'])->name('collection.index');
Route::post('/collection', [CollectionController::class, 'store'])->name('collection.store');
Route::delete('/collection/{entry}', [CollectionController::class, 'destroy'])->name('collection.destroy');

Route::get('/settings', fn () => Inertia::render('Settings/Index'))->name('settings');

Route::post('/locale', function (Request $request) {
    $validated = $request->validate([
        'locale' => ['required', 'string', 'in:'.implode(',', SetLocale::SUPPORTED)],
    ]);

    $request->session()->put('locale', $validated['locale']);

    return back();
})->name('locale.set');
