<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Orders\Commands\AutoConfirmDeliveredOrders;
use App\Modules\Orders\Commands\FlagOverduePreparations;
use App\Providers\ModuleServiceProvider;
use App\Shared\Enums\UserType;
use App\Shared\Middleware\EnsureCorrectPortal;
use App\Shared\Middleware\EnsureTwoFactorEnrolled;
use App\Shared\Middleware\EnsureUserIsActive;
use App\Shared\Middleware\NotOnAdminDomain;
use App\Shared\Middleware\ScopeAdminSessionCookie;
use App\Shared\Security\AdminDomain;
use App\Shared\Security\VendorDomain;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

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

            // Vendor Center gets the same treatment: registered before the
            // unconstrained customer routes so its domain always wins.
            Route::middleware('web')
                ->domain(config('app.vendor_domain'))
                ->group(base_path('routes/vendors.php'));

            // NotOnAdminDomain 404s these on the admin subdomain: they carry
            // no domain constraint (must work on localhost, 127.0.0.1, and
            // production alike), so without it /register etc. would also
            // answer on the admin origin.
            Route::middleware(['web', NotOnAdminDomain::class])
                ->group(base_path('routes/web.php'));

            // Module routes load last, and from here rather than a service
            // provider, so the admin.php routes above always win the match
            // on the admin subdomain.
            ModuleServiceProvider::loadRoutes();

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Module-housed artisan commands (auto-discovery only scans app/Console).
    ->withCommands([
        AutoConfirmDeliveredOrders::class,
        FlagOverduePreparations::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Must run before Laravel's own StartSession middleware (appended by
        // the framework to the "web" group) so the admin subdomain gets its
        // own session cookie name/domain instead of the customer app's.
        $middleware->web(prepend: [
            ScopeAdminSessionCookie::class,
        ]);

        // The Paystack webhook is a server-to-server POST with no session or
        // CSRF token; it is authenticated by its signature instead (Sprint 4).
        $middleware->validateCsrfTokens(except: ['webhooks/paystack']);

        $middleware->web(append: [
            // Kills any live session the moment a user is suspended or
            // banned (docs/FirstMaket_Implementation_Plan.md Sprint 2).
            EnsureUserIsActive::class,
            // Logs out sessions that are on the wrong portal for their user
            // type (staff on the customer site, customers on the admin one).
            EnsureCorrectPortal::class,
            HandleInertiaRequests::class,
        ]);

        // Laravel's middleware priority sorting would otherwise run auth
        // before NotOnAdminDomain, redirecting guests instead of 404ing
        // customer routes on the admin subdomain. The priority list entry
        // for auth is the AuthenticatesRequests interface, not the concrete
        // Authenticate class.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: NotOnAdminDomain::class,
        );

        // Send guests and already-authenticated users to the login/dashboard
        // that belongs to the domain they are on, never across portals.
        $middleware->redirectGuestsTo(fn (Request $request) => match (true) {
            AdminDomain::matches($request) => route('admin.login'),
            VendorDomain::matches($request) => route('vendor.login'),
            default => route('login'),
        });

        $middleware->redirectUsersTo(fn (Request $request) => match (true) {
            AdminDomain::matches($request) => route('admin.dashboard'),
            VendorDomain::matches($request) => route('vendor.dashboard'),
            default => route('dashboard'),
        });

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'two_factor.enrolled' => EnsureTwoFactorEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Friendly, branded error pages instead of the raw Symfony/Laravel
        // ones — e.g. a customer poking at an admin URL sees a clear
        // "staff only" message, not a bare 403. Raw responses are kept for
        // JSON clients, and for 5xx while debug mode is on so local
        // stack traces stay visible.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            $pages = [
                403 => ['Access denied', "You don't have permission to view this page."],
                404 => ['Page not found', "We couldn't find the page you're looking for. It may have been moved or no longer exists."],
                419 => ['Page expired', 'Your session expired. Please go back and try again.'],
                500 => ['Something went wrong', 'An unexpected error occurred on our side. Please try again shortly.'],
                503 => ['Briefly unavailable', 'FirstMaket is undergoing maintenance. Please check back in a few minutes.'],
            ];

            if (! isset($pages[$status]) || $request->expectsJson()) {
                return $response;
            }

            if ($status >= 500 && config('app.debug')) {
                return $response;
            }

            [$title, $message] = $pages[$status];

            if ($status === 403 && AdminDomain::matches($request) && $request->user()?->user_type !== UserType::Staff) {
                $message = 'This area is for FirstMaket staff only. If you are a customer or vendor, please use the main FirstMaket site.';
            }

            if ($status === 403 && VendorDomain::matches($request) && $request->user()?->user_type !== UserType::Vendor) {
                $message = 'This area is the FirstMaket Vendor Center, for approved vendor accounts only. Apply to sell from the main FirstMaket site.';
            }

            // On the portal subdomains "/" is not the marketplace — send
            // "Go to homepage" to the main site instead.
            $isPortal = AdminDomain::matches($request) || VendorDomain::matches($request);

            return Inertia::render('Error', [
                'status' => $status,
                'title' => $title,
                'message' => $message,
                'homeUrl' => $isPortal ? config('app.url') : '/',
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
