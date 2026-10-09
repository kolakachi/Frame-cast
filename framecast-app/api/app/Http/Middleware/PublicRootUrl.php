<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Links are built for the public site even when a request came over the internal network. The MCP server calls the
 * API as http://nginx and Node's fetch drops the Host header it sets, so its download links read https://nginx/…
 * (2026-10-09). A host without a dot is internal: links use APP_URL instead. Public hosts keep their own.
 */
class PublicRootUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        if ($host !== '' && ! str_contains($host, '.') && $host !== 'localhost') {
            URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
        }
        return $next($request);
    }
}
