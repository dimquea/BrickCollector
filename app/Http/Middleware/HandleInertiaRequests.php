<?php

namespace App\Http\Middleware;

use Closure;
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
    /**
     * Адрес страницы, который Inertia положит в объект страницы.
     *
     * Из него берётся история браузера и router.reload(), поэтому под префиксом
     * он должен быть с префиксом — иначе перезагрузка уйдёт мимо аддона.
     */
    public function urlResolver(): ?Closure
    {
        return fn (Request $request) => $request->attributes->get(HandleIngress::ATTRIBUTE, '')
            .$request->getRequestUri();
    }

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
