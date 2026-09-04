<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// Local-only request log for analysis: one line per API call (method, path,
// status, duration, user/role) written to its own file so it doesn't mix
// with laravel.log. Never runs outside `local` (docker-compose.dokploy.yml
// sets APP_ENV=production), so it's safe to leave wired into the api group.
class LogApiRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            return $next($request);
        }

        $start = microtime(true);
        $status = null;

        // A downstream middleware/controller (e.g. auth:sanctum rejecting an
        // unauthenticated request) can throw instead of returning a
        // Response — without catching that here, the log line below never
        // runs and every failed request silently disappears from the log,
        // which defeats the point of logging "all" requests for analysis.
        // Re-thrown as-is so Laravel's normal exception handling still
        // produces the actual response sent to the client.
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $e) {
            $status = match (true) {
                $e instanceof AuthenticationException => 401,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            throw $e;
        } finally {
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/api-requests.log'),
            ])->info(sprintf(
                '%s %s -> %d (%dms) user=%s role=%s ip=%s',
                $request->method(),
                '/'.ltrim($request->path(), '/'),
                $status,
                (int) ((microtime(true) - $start) * 1000),
                $request->user()?->id ?? '-',
                $request->user()?->getRoleNames()->implode(',') ?: '-',
                $request->ip(),
            ));
        }
    }
}
