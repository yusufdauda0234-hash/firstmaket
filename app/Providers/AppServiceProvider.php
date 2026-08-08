<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Cart\Listeners\MergeGuestCartOnLogin;
use App\Modules\Catalog\Listeners\DelistSuspendedVendorProducts;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\HomeDataService;
use App\Modules\Catalog\Services\RuleBasedListingAnalyzer;
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
use App\Shared\Contracts\AiListingAnalyzerContract;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Contracts\BankAccountResolverContract;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\SmsSenderContract;
use App\Shared\Features;
use App\Shared\Services\AuditLogger;
use App\Shared\Services\Sms\LogSmsSender;
use App\Shared\Services\Sms\SmartSmsSolutionsSender;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Resend\Client as ResendClient;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;
use RuntimeException;

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

        /*
         * Production only, so local development is untouched.
         *
         * Every URL the app writes — password reset links, Paystack callback
         * URLs, OAuth redirects — is built from the request. Behind a proxy
         * that forwards plain HTTP those come out as http://, and a payment
         * callback on http:// is one downgrade away from being read in
         * transit. TrustProxies fixes the detection; this makes it explicit
         * so a misconfigured proxy header cannot quietly produce insecure
         * links.
         */
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        /*
         * Refuse to boot a production site with debug on.
         *
         * The debug page prints environment variables — database password,
         * Paystack secret key, Resend key, OAuth secrets — to anyone who can
         * provoke an exception. A deploy that forgets APP_DEBUG=false should
         * fail loudly at startup rather than serve one 500 and hand over the
         * keys.
         */
        if ($this->app->environment('production') && config('app.debug')) {
            throw new RuntimeException(
                'APP_DEBUG must be false in production — the error page leaks every secret in .env.'
            );
        }

        // Cross-module reactions travel through domain events, never direct
        // module-to-module calls (docs/FirstMaket_Developer_Guidelines.md).
        Event::listen(VendorSuspended::class, DelistSuspendedVendorProducts::class);
        Event::listen(OrderPaid::class, NotifyVendorOfSale::class);
        Event::listen(OrderDeliveryConfirmed::class, CreditVendorEarnings::class);
        Event::listen(OrderStatusChanged::class, NotifyCustomerOfOrderStatus::class);

        /*
         * Keep the storefront home page honest.
         *
         * Its strips are cached for five minutes and nothing used to clear
         * them, so a listing that had just been approved stayed invisible —
         * which reads as the approval not having worked. Three cache deletes
         * on a product or category write is far cheaper than that confusion.
         */
        $forgetHomeCache = fn () => HomeDataService::forget();

        Product::saved($forgetHomeCache);
        Product::deleted($forgetHomeCache);
        Category::saved($forgetHomeCache);
        Category::deleted($forgetHomeCache);

        // A guest fills a cart, then signs in to check out — carry the
        // session cart over instead of letting it vanish (Sprint 8).
        Event::listen(Login::class, MergeGuestCartOnLogin::class);

        // Sprint 7: SMS notification channel + delivery-failure monitoring.
        Notification::extend('sms', fn ($app) => new SmsChannel($app->make(SmsSenderContract::class)));
        Event::listen(NotificationSent::class, [RecordNotificationDelivery::class, 'handleSent']);
        Event::listen(NotificationFailed::class, [RecordNotificationDelivery::class, 'handleFailed']);

        // The resend-php SDK's Resend::client() builds its Guzzle client with
        // no timeout at all, so a slow/unreachable Resend API hangs every
        // mail send until PHP's max_execution_time kills the whole request
        // (fatal-errored a vendor registration in practice). Rebuild the
        // transport with a bounded HTTP client instead of Laravel's default.
        Mail::extend('resend', function (array $config) {
            $apiKey = ApiKey::from($config['key'] ?? config('services.resend.key'));
            $baseUri = BaseUri::from(getenv('RESEND_BASE_URL') ?: 'api.resend.com');
            $headers = Headers::withAuthorization($apiKey);

            $client = new GuzzleClient(['connect_timeout' => 5, 'timeout' => 10]);
            $transporter = new HttpTransporter($client, $baseUri, $headers);

            return new ResendTransport(new ResendClient($transporter));
        });

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
