<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the language chosen by the visitor.
 *
 * The choice lives in the session for now; once the settings screen exists it
 * will move into the settings table and this middleware will read it there.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'ru'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
