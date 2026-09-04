<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // spatie/laravel-permission's middleware aliases aren't registered
        // automatically in Laravel 11 (no Http/Kernel.php to hook into
        // anymore) — routes/api.php's `role:planificador` etc. throw
        // "Target class [role] does not exist" without this.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // ForceJsonResponse (prepend, see class docblock) must run before
        // auth:sanctum so the guest-redirect check already sees an
        // `Accept: application/json` request. LogApiRequests logs every
        // /api/* request (method, path, status, duration, user/role) to
        // storage/logs/api-requests.log — no-ops outside APP_ENV=local.
        $middleware->api(
            prepend: [ForceJsonResponse::class],
            append: [LogApiRequests::class],
        );

        // `append` above only controls registration order, but Authenticate
        // is one of Laravel's "priority" middleware (session/auth/etc.),
        // which the framework always sorts ahead of ordinary appended
        // middleware regardless of registration order. Without pinning
        // LogApiRequests explicitly before it here too, an unauthenticated
        // request throws out of auth:sanctum before ever reaching
        // LogApiRequests — silently dropping every failed request from the
        // log, which defeats the point of logging "all" requests. The
        // priority list keys on the *contract* Authenticate implements
        // (AuthenticatesRequests), not the concrete class — using the
        // concrete class here silently no-ops instead of erroring.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: LogApiRequests::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // This app is API-only — there's no `login` web route to redirect
        // guests to. Without this, a request under /api/* that doesn't
        // *look* like an AJAX call (e.g. no `Accept: application/json`
        // header — hitting the URL directly in a browser tab, or a client
        // that forgot the header) makes Laravel's unauthenticated-guest
        // handler try to build a `route('login')` URL that doesn't exist,
        // crashing with a 500 RouteNotFoundException instead of a clean
        // 401. Forcing JSON rendering for every /api/* request sidesteps
        // that redirect path entirely.
        $exceptions->shouldRenderJsonWhen(fn ($request, $throwable) => $request->is('api/*') || $request->expectsJson());
    })->create();
