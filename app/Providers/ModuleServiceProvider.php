<?php

namespace App\Providers;

use App\Shared\Middleware\NotOnAdminDomain;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-loads each app/Modules/{Name}/routes.php. Modules communicate with
 * each other through domain events or Shared/Contracts interfaces, never by
 * reaching into another module's models directly (see
 * docs/firstmarket_Developer_Guidelines.md and
 * docs/firstmarket_Implementation_Plan.md section 1.1).
 *
 * loadRoutes() is called from bootstrap/app.php's routing closure — not from
 * boot() — because registration order is what makes domain isolation work:
 * routes/admin.php must be registered first so /login on the admin subdomain
 * matches the staff login, not the customer one.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Modules whose routes.php is reserved for the isolated admin subdomain
     * (routes/admin.php) instead of the customer/vendor-facing domain.
     *
     * @var list<string>
     */
    private const ADMIN_ONLY_MODULES = ['Admin'];

    public static function loadRoutes(): void
    {
        foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [] as $modulePath) {
            $moduleName = basename($modulePath);
            $routesFile = $modulePath.'/routes.php';

            if (! is_file($routesFile) || in_array($moduleName, self::ADMIN_ONLY_MODULES, true)) {
                continue;
            }

            // NotOnAdminDomain keeps these customer/vendor routes from also
            // answering on the isolated admin subdomain.
            Route::middleware(['web', NotOnAdminDomain::class])->group($routesFile);
        }
    }
}
