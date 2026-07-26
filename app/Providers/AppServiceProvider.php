<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Catalog\Listeners\DelistSuspendedVendorProducts;
use App\Modules\Catalog\Services\RuleBasedListingAnalyzer;
use App\Modules\Notifications\Listeners\RecordNotificationDelivery;
use App\Modules\Notifications\Services\SmsChannel;
use App\Modules\Orders\Events\OrderDeliveryConfirmed;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Listeners\NotifyCustomerOfOrderStatus;
use App\Modules\Payments\Services\PaystackBankResolver;
use App\Modules\Payments\Services\PaystackGateway;
use App\Modules\Savings\Services\AiScoredPlanEligibilityChecker;
use App\Modules\Vendor\Events\VendorSuspended;
use App\Modules\Vendor\Listeners\CreditVendorEarnings;
use App\Modules\Vendor\Listeners\NotifyVendorOfSale;
use App\Shared\Contracts\AiListingAnalyzerContract;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Contracts\BankAccountResolverContract;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\PlanEligibilityContract;
use App\Shared\Contracts\SmsSenderContract;
use App\Shared\Features;
use App\Shared\Services\AuditLogger;
use App\Shared\Services\Sms\LogSmsSender;
use App\Shared\Services\Sms\SmartSmsSolutionsSender;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditLoggerContract::class, AuditLogger::class);

        $this->app->bind(SmsSenderContract::class, fn () => match (config('services.sms.driver')) {
            'smartsmssolutions' => new SmartSmsSolutionsSender,
            default => new LogSmsSender,
        });

        // Payment gateway (Sprint 4). Paystack is the MVP driver.
        $this->app->bind(PaymentGatewayContract::class, PaystackGateway::class);

        // Vendor payout bank verification (Sprint 6).
        $this->app->bind(
            BankAccountResolverContract::class,
            PaystackBankResolver::class,
        );

        // Multi-product plan bundling eligibility (Sprint 8, swapped Sprint
        // 9 for an AI-scored implementation that keeps the rule-based
        // checker as its explicit fallback/floor — see
        // AiScoredPlanEligibilityChecker).
        $this->app->bind(PlanEligibilityContract::class, AiScoredPlanEligibilityChecker::class);

        // Listing Review Assistant (Sprint 9) — advisory only, never
        // approves/rejects. No real provider is configured by default, so
        // this resolves to the deterministic rule-based driver; a future
        // AI_PROVIDER_DRIVER value adds a case here without touching the job
        // or admin UI that consume the contract.
        $this->app->bind(AiListingAnalyzerContract::class, fn () => match (config('services.ai.driver')) {
            default => new RuleBasedListingAnalyzer,
        });
    }

    public function boot(): void
    {
        Features::register();

        // Cross-module reactions travel through domain events, never direct
        // module-to-module calls (docs/FirstMaket_Developer_Guidelines.md).
        Event::listen(VendorSuspended::class, DelistSuspendedVendorProducts::class);
        Event::listen(OrderPaid::class, NotifyVendorOfSale::class);
        Event::listen(OrderDeliveryConfirmed::class, CreditVendorEarnings::class);
        Event::listen(OrderStatusChanged::class, NotifyCustomerOfOrderStatus::class);

        // Sprint 7: SMS notification channel + delivery-failure monitoring.
        Notification::extend('sms', fn ($app) => new SmsChannel($app->make(SmsSenderContract::class)));
        Event::listen(NotificationSent::class, [RecordNotificationDelivery::class, 'handleSent']);
        Event::listen(NotificationFailed::class, [RecordNotificationDelivery::class, 'handleFailed']);

        // Super Administrator gets every ability automatically, so newly
        // added permissions never need a reseed
        // (docs/FirstMaket_Developer_Guidelines.md section 8).
        Gate::before(fn (User $user) => $user->hasRole('Super Administrator') ?: null);

        // Baseline password policy enforced everywhere Password::defaults() is
        // used (registration, reset, account settings) — mirrors the live
        // client-side checklist in Components/domain/auth/PasswordFields.
        // In production, also reject passwords found in known breach corpora.
        Password::defaults(fn () => Password::min(8)
            ->letters()
            ->numbers()
            ->when($this->app->isProduction(), fn (Password $rule) => $rule->uncompromised()));
    }
}
