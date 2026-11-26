<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The untasked.run router must set Host to the fly.dev upstream for Fly routing.
 * It also forwards X-Untasked-Original-Host (branded / custom host). Prefer that
 * host for URL generation so Vite/Ziggy/Inertia stay same-origin for the visitor.
 */
class PreferBrandedHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $original = strtolower(trim((string) $request->headers->get('X-Untasked-Original-Host', '')));
        if ($original !== ''
            && ! str_contains($original, '/')
            && ! str_contains($original, ' ')
            && filter_var($original, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        ) {
            $request->headers->set('Host', $original);
            $request->server->set('HTTP_HOST', $original);
            $request->server->set('SERVER_NAME', $original);
        }

        return $next($request);
    }
}
