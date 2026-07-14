<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Auto-loads each app/Modules/{Name}/routes.php. Modules communicate with
 * each other through domain events or Shared/Contracts interfaces, never by
 * reaching into another module's models directly (see
 * docs/firstmarket_Developer_Guidelines.md and
 * docs/firstmarket_Implementation_Plan.md section 1.1).
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

    public function boot(): void
    {
        foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [] as $modulePath) {
            $moduleName = basename($modulePath);
            $routesFile = $modulePath.'/routes.php';

            if (! is_file($routesFile) || in_array($moduleName, self::ADMIN_ONLY_MODULES, true)) {
                continue;
            }

            $this->app['router']->middleware('web')->group($routesFile);
        }
    }
}
