<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home'))->name('home');

Route::get('/settings', fn () => Inertia::render('Settings/Index'))->name('settings');

Route::post('/locale', function (Request $request) {
    $validated = $request->validate([
        'locale' => ['required', 'string', 'in:'.implode(',', SetLocale::SUPPORTED)],
    ]);

    $request->session()->put('locale', $validated['locale']);

    return back();
})->name('locale.set');
