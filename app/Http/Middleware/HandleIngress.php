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
 * addresses we hand back need the prefix, and that is exactly what forcing the
 * root URL does — url(), route(), asset() and the pagination links all come out
 * right.
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

        if ($prefix !== '') {
            // The proxy speaks to us over plain http even when the browser is on
            // https, and it says so in the forwarded headers. Reading them here
            // rather than trusting the proxy globally keeps the trust in one
            // place, next to the decision that put it there.
            $scheme = $request->header('X-Forwarded-Proto') ?: $request->getScheme();
            $host = $request->header('X-Forwarded-Host') ?: $request->getHttpHost();

            URL::forceScheme($scheme);
            URL::forceRootUrl("{$scheme}://{$host}{$prefix}");

            // Постраничка строит ссылки не через url(), а от адреса запроса —
            // а он к нам приходит без префикса. Отправляем её тем же путём, что
            // и всё остальное.
            Paginator::currentPathResolver(fn () => URL::current());
        }

        return $next($request);
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
