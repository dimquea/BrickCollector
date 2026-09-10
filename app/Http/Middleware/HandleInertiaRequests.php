<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page.
     *
     * Translations are handed to the frontend here so there is only one
     * dictionary in the project: the Laravel language files. See CLAUDE.md,
     * "Internationalisation".
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'locale' => $locale,
            'supportedLocales' => SetLocale::SUPPORTED,
            'translations' => Lang::get('app', [], $locale),
            'flash' => fn () => $request->session()->get('flash'),
        ];
    }
}
