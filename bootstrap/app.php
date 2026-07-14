<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Shared\Middleware\EnsureTwoFactorEnrolled;
use App\Shared\Middleware\ScopeAdminSessionCookie;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Registered manually (instead of the web/api/then shorthand) so the
        // admin-subdomain routes are matched FIRST. Laravel resolves the
        // first route whose pattern matches, and a route without a domain
        // constraint matches ANY host — so if routes/web.php's "/" were
        // registered before the domain-scoped admin group, it would answer
        // requests to the admin subdomain too, silently defeating the
        // isolation in App\Shared\Middleware\ScopeAdminSessionCookie.
        using: function () {
            Route::middleware('web')
                ->domain(config('app.admin_domain'))
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Must run before Laravel's own StartSession middleware (appended by
        // the framework to the "web" group) so the admin subdomain gets its
        // own session cookie name/domain instead of the customer app's.
        $middleware->web(prepend: [
            ScopeAdminSessionCookie::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'two_factor.enrolled' => EnsureTwoFactorEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
