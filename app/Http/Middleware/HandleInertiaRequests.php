<?php

namespace App\Http\Middleware;

use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Services\HomeDataService;
use App\Modules\Catalog\Services\LocalePreference;
use App\Modules\Orders\Services\DeliveryPricing;
use App\Shared\Enums\Locale;
use App\Shared\Security\AdminDomain;
use App\Shared\Security\VendorDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        // Header categories are shared so any customer-facing page can render
        // the marketplace PublicLayout without threading categories through
        // every controller. Skipped on the admin/vendor portals, which don't
        // use that layout. Cached in HomeDataService, so this is cheap.
        $isPortal = AdminDomain::matches($request) || VendorDomain::matches($request);

        return [
            ...parent::share($request),
            'categories' => $isPortal ? [] : fn () => app(HomeDataService::class)->categories(),
            // Header cart badge. Guests have a cart too (session-backed), so
            // this is not gated on $user. Closure-wrapped, so the query only
            // runs for pages that actually read it.
            'cartCount' => $isPortal ? 0 : fn () => app(CartService::class)->count($user),
            // The free-delivery promise in the header used to be the literal
            // string "NGN 15,000". Delivery is priced per state on the admin
            // rates screen now, so the banner has to follow it — or vanish
            // when no rate offers free delivery at all, rather than promising
            // something the checkout will not honour.
            'freeDeliveryFromKobo' => $isPortal ? 0 : fn () => app(DeliveryPricing::class)->lowestFreeThresholdKobo(),
            'auth' => [
                'user' => $user ? [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'phoneVerified' => $user->hasVerifiedPhone(),
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                    // Shared rather than passed per page: the Vendor Center
                    // nav has to know, and a nav that offers links the
                    // middleware then refuses is worse than no nav at all.
                    'vendorStatus' => $user->vendorProfile?->status->value,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Local/debug only — App\Modules\Identity\Controllers\PhoneVerificationController.
                'devOtpCode' => fn () => $request->session()->get('devOtpCode'),
            ],
            'supportHotline' => config('firstmaket.support.hotline'),
            // Language + display currency. Portals stay in English naira:
            // they are staff tools reading the ledger, and a converted figure
            // on a payout or reconciliation screen would be actively harmful.
            'i18n' => $isPortal ? null : fn () => $this->i18n($request),
            // Absolute URL of the main marketplace — portal pages (Vendor
            // Center, admin) need it because routes without a domain
            // constraint generate on the current origin.
            'mainSiteUrl' => rtrim(config('app.url'), '/'),
        ];
    }

    /**
     * Everything the storefront needs to render itself in the shopper's
     * language and currency.
     *
     * @return array<string, mixed>
     */
    private function i18n(Request $request): array
    {
        $preference = app(LocalePreference::class);

        $locale = $preference->locale($request);
        $currency = $preference->currency($request);

        return [
            'locale' => $locale->value,
            'intlTag' => $locale->intlTag(),
            'locales' => Locale::options(),
            'translations' => $this->translations($locale),
            'country' => $preference->countryCode($request),
            'currency' => $currency->toDisplayArray(),
            'currencies' => $preference->activeCurrencies()
                ->map(fn ($c) => $c->toDisplayArray())
                ->values()
                ->all(),
        ];
    }

    /**
     * The chosen language's strings laid over English.
     *
     * The merge matters: a key we have not translated yet falls back to
     * readable English instead of rendering the raw key, so a partially
     * translated page degrades gracefully rather than breaking.
     *
     * @return array<string, string>
     */
    private function translations(Locale $locale): array
    {
        $english = $this->loadTranslationFile(Locale::default());

        if ($locale === Locale::default()) {
            return $english;
        }

        return [...$english, ...$this->loadTranslationFile($locale)];
    }

    /** @return array<string, string> */
    private function loadTranslationFile(Locale $locale): array
    {
        $path = lang_path("{$locale->value}.json");

        if (! is_file($path)) {
            return [];
        }

        return Cache::remember(
            "translations.{$locale->value}.".filemtime($path),
            now()->addDay(),
            function () use ($path) {
                $decoded = json_decode((string) file_get_contents($path), true);

                return is_array($decoded) ? $decoded : [];
            },
        );
    }
}
