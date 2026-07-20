<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Catalog\Listeners\DelistSuspendedVendorProducts;
use App\Modules\Identity\Services\PaystackBvnVerifier;
use App\Modules\Identity\Services\YouverifyNinVerifier;
use App\Modules\Notifications\Listeners\RecordNotificationDelivery;
use App\Modules\Notifications\Services\SmsChannel;
use App\Modules\Orders\Events\OrderDeliveryConfirmed;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Listeners\NotifyCustomerOfOrderStatus;
use App\Modules\Payments\Services\PaystackBankResolver;
use App\Modules\Payments\Services\PaystackGateway;
use App\Modules\Vendor\Events\VendorSuspended;
use App\Modules\Vendor\Listeners\CreditVendorEarnings;
use App\Modules\Vendor\Listeners\NotifyVendorOfSale;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Contracts\BankAccountResolverContract;
use App\Shared\Contracts\BvnVerifierContract;
use App\Shared\Contracts\NinVerifierContract;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\SmsSenderContract;
use App\Shared\Features;
use App\Shared\Services\AuditLogger;
use App\Shared\Services\Sms\LogSmsSender;
use App\Shared\Services\Sms\TermiiSmsSender;
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
            'termii' => new TermiiSmsSender,
            default => new LogSmsSender,
        });

        // Single implementation per provider slot for now; additional
        // drivers (Smile Identity, Prembly) plug in through these contracts
        // when needed (docs/firstmarket_Implementation_Plan.md Sprint 2).
        $this->app->bind(BvnVerifierContract::class, PaystackBvnVerifier::class);
        $this->app->bind(NinVerifierContract::class, YouverifyNinVerifier::class);

        // Payment gateway (Sprint 4). Paystack is the MVP driver.
        $this->app->bind(PaymentGatewayContract::class, PaystackGateway::class);

        // Vendor payout bank verification (Sprint 6).
        $this->app->bind(
            BankAccountResolverContract::class,
            PaystackBankResolver::class,
        );
    }

    public function boot(): void
    {
        Features::register();

        // Cross-module reactions travel through domain events, never direct
        // module-to-module calls (docs/firstmarket_Developer_Guidelines.md).
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
        // (docs/firstmarket_Developer_Guidelines.md section 8).
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
