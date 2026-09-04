<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Forces every /api/* request to be treated as "expects JSON", regardless
// of what Accept header the client actually sent. Without this, a plain
// browser tab hitting an /api/* URL directly (or any client that omits
// `Accept: application/json`) makes Laravel's guest-redirect logic try to
// build a `route('login')` URL for a "web" login page this API-only app
// doesn't have — crashing with a 500 RouteNotFoundException instead of a
// clean 401 `{"message":"Unauthenticated."}`.
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
