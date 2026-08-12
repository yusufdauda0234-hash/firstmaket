<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Services\HomeDataService;
use App\Modules\Catalog\Services\LocalePreference;
use App\Modules\Customer\Models\Wishlist;
use App\Modules\Orders\Services\DeliveryPricing;
use App\Modules\Returns\Services\ReturnPolicy;
use App\Modules\Support\Models\ContentPage;
use App\Modules\Support\Models\SupportTicket;
use App\Shared\Enums\Locale;
use App\Shared\Enums\TicketStatus;
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
        $isAdmin = AdminDomain::matches($request);
        $isVendor = VendorDomain::matches($request);
        $isPortal = $isAdmin || $isVendor;

        return [
            ...parent::share($request),
            /*
             * Which origin is being served.
             *
             * A handful of pages are reachable from more than one portal —
             * support and notifications carry no domain constraint, so a
             * vendor opens them on the Vendor Center origin under their own
             * vendor session. Those pages read this to pick their chrome;
             * without it they would render the storefront header on a
             * subdomain that has no storefront.
             */
            'portal' => $isAdmin ? 'admin' : ($isVendor ? 'vendor' : 'customer'),
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
            /*
             * Header bell and open-ticket badge.
             *
             * Shared rather than threaded through every controller, because
             * they sit in the layout and therefore appear on every page —
             * passing them per-controller would mean the bell silently
             * emptying itself on whichever screen somebody forgot.
             *
             * Closure-wrapped, so the queries only run for a signed-in
             * request that actually renders them.
             */
            'unreadNotifications' => $user === null ? 0 : fn () => $user->unreadNotifications()->count(),
            'openTickets' => $user === null ? 0 : fn () => SupportTicket::query()
                ->where('customer_id', $user->id)
                ->whereIn('status', [TicketStatus::Open, TicketStatus::Pending])
                ->count(),
            // Footer legal links. Shared rather than hardcoded in the layout
            // so that publishing a page in admin puts it on the site, and
            // unpublishing one takes the link away instead of leaving a 404
            // in the footer of every page.
            'legalLinks' => $isPortal ? [] : fn () => $this->legalLinks(),
            // Live-chat widget config. Storefront only: the staff and vendor
            // portals do not get a customer support widget.
            'supportChat' => $isPortal ? null : fn () => [
                'provider' => (string) Setting::get('support.chat_provider', 'none'),
                'propertyId' => (string) Setting::get('support.chat_property_id', ''),
                'widgetId' => (string) Setting::get('support.chat_widget_id', ''),
                'forGuests' => (bool) Setting::get('support.chat_for_guests', true),
            ],
            // Which products this customer has saved, so a product card can
            // draw its heart in the right state. Shared rather than added to
            // every product payload: the same card renders on the home page,
            // the catalogue, search, a category, the cart's recommendations
            // and quick view, and each of those would otherwise have to
            // remember to join the wishlist. Closure-wrapped, so the query
            // only runs for pages that read it, and it is uuids only — one
            // indexed column, no rows to hydrate.
            'wishlistUuids' => $isPortal || $user === null
                ? []
                : fn () => Wishlist::query()
                    ->where('user_id', $user->id)
                    ->join('products', 'products.id', '=', 'wishlists.product_id')
                    ->pluck('products.uuid')
                    ->all(),
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
            // The storefront advertised "30-day returns" as static text while
            // the enforced window was 7 — a promise the system would refuse.
            // Both now read the same setting.
            'returnWindowDays' => $isPortal ? null : fn () => app(ReturnPolicy::class)->windowDays(),
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
     * Published pages the admin has ticked for the footer.
     *
     * Cached: this runs on every storefront request and the answer changes a
     * few times a year. Cleared by ContentPage's saved/deleted hooks, so an
     * edit shows up immediately rather than whenever the hour turns over.
     *
     * @return array<int, array{title: string, url: string}>
     */
    private function legalLinks(): array
    {
        return Cache::remember(ContentPage::FOOTER_CACHE_KEY, now()->addDay(), fn () => ContentPage::query()
            ->published()
            ->where('show_in_footer', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (ContentPage $page) => ['title' => $page->title, 'url' => $page->url()])
            ->all());
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
