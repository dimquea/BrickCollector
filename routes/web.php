<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DictionaryController;
use App\Http\Controllers\SettingsController;
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
Route::get('/collection/{entry}', [CollectionController::class, 'show'])->name('collection.show');
Route::patch('/collection/{entry}', [CollectionController::class, 'update'])->name('collection.update');
Route::delete('/collection/{entry}', [CollectionController::class, 'destroy'])->name('collection.destroy');
Route::patch('/collection/lot/{item}', [CollectionController::class, 'updateLost'])->name('collection.lot.lost');

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
