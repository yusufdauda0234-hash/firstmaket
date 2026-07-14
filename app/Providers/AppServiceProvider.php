<?php

namespace App\Providers;

use App\Models\User;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Features;
use App\Shared\Services\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditLoggerContract::class, AuditLogger::class);
    }

    public function boot(): void
    {
        Features::register();

        // Super Administrator gets every ability automatically, so newly
        // added permissions never need a reseed
        // (docs/firstmarket_Developer_Guidelines.md section 8).
        Gate::before(fn (User $user) => $user->hasRole('Super Administrator') ?: null);
    }
}
