<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleIngress;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Картинки — без группы web: см. routes/images.php.
        then: fn () => Route::group([], __DIR__.'/../routes/images.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Корень ссылок должен быть выставлен раньше, чем что-либо начнёт их
        // строить.
        $middleware->web(prepend: [
            HandleIngress::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
