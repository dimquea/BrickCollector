<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the application live under a proxy's path prefix.
 *
 * Home Assistant Ingress serves an add-on at /api/hassio_ingress/<token>/ and
 * proxies the request with that prefix already stripped, naming it in the
 * X-Ingress-Path header. Routing therefore sees the paths it expects; only the
 * addresses we hand back need the prefix put in front.
 *
 * **Every address we emit is relative to the site root** — no scheme, no host.
 * That is not tidiness, it is the only thing that can work: the add-on is
 * reached at http://192.168.0.10 from inside the house while the browser may be
 * on https://home.example, and the forwarded headers describe Home Assistant's
 * own listener rather than whatever proxy sits in front of it. An absolute URL
 * built from what we can see is a promise about an origin we do not know, and
 * the browser refuses it as mixed content. A root-relative one resolves against
 * the page the visitor is actually on, whichever that is.
 *
 * The obvious-looking alternative, telling the request itself about the prefix
 * through SCRIPT_NAME, breaks the site root once routes are cached: Laravel
 * matches a copy of the request with the trailing slash trimmed off, and at the
 * root that slash is the only thing by which Symfony recognises the prefix as a
 * base URL. The path then matches nothing and "/" answers 405.
 *
 * The header is honoured only where the deployment says so: the application can
 * also be reached straight on a port, and there the header would be a stranger's
 * way of rewriting every link on the page.
 */
class HandleIngress
{
    /** Where the request stashes the prefix for the root view to read. */
    public const ATTRIBUTE = 'ingress_path';

    public function handle(Request $request, Closure $next): Response
    {
        $prefix = $this->prefix($request);

        $request->attributes->set(self::ATTRIBUTE, $prefix);

        if ($prefix === '') {
            return $next($request);
        }

        // Стили и скрипты: единственное место, где Laravel умеет отдавать
        // адрес от корня, а не абсолютный.
        URL::useAssetOrigin($prefix);

        // Постраничка строит ссылки не через url(), а от адреса запроса — а он
        // приходит к нам без префикса.
        Paginator::currentPathResolver(fn () => $prefix.$request->getPathInfo());

        return $this->makeRedirectRelative($next($request), $prefix, $request);
    }

    /**
     * Переписывает Location редиректа в путь от корня.
     *
     * Laravel строит его через url(), то есть абсолютным и от того хоста,
     * которым нас видит Home Assistant. Браузер за внешним прокси уйдёт по нему
     * на другое происхождение — в лучшем случае получит смешанное содержимое, в
     * худшем просто не дойдёт. Относительный Location разрешён и делает ровно
     * то, что нужно.
     */
    private function makeRedirectRelative(Response $response, string $prefix, Request $request): Response
    {
        if (! $response->isRedirection() || ! $response->headers->has('Location')) {
            return $response;
        }

        $location = (string) $response->headers->get('Location');
        $host = parse_url($location, PHP_URL_HOST);

        // Уводит наружу — не наше дело: картинки, например, отправляют браузер
        // прямо на BrickLink, и префикс там был бы бессмыслицей.
        if ($host !== null && $host !== $request->getHost()) {
            return $response;
        }

        $path = parse_url($location, PHP_URL_PATH);

        if ($path === false || $path === null) {
            return $response;
        }

        $query = parse_url($location, PHP_URL_QUERY);
        $fragment = parse_url($location, PHP_URL_FRAGMENT);

        $response->headers->set('Location', $prefix.$path
            .($query === null ? '' : '?'.$query)
            .($fragment === null ? '' : '#'.$fragment));

        return $response;
    }

    /** The prefix this request arrived under, or an empty string. */
    private function prefix(Request $request): string
    {
        if (! config('brickcollector.trust_ingress')) {
            return '';
        }

        $prefix = rtrim((string) $request->header('X-Ingress-Path'), '/');

        return $prefix === '' ? '' : '/'.ltrim($prefix, '/');
    }
}
