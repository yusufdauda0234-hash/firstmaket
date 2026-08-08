<?php

use App\Modules\Catalog\Models\DisplayCurrency;
use App\Modules\Catalog\Services\LocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedCurrencies(): void
{
    DisplayCurrency::query()->create([
        'code' => 'NGN', 'symbol' => '₦', 'name' => 'Nigerian Naira',
        'units_per_naira' => '1', 'decimals' => 0, 'is_active' => true, 'sort_order' => 0,
    ]);
    DisplayCurrency::query()->create([
        'code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar',
        'units_per_naira' => '0.00065', 'decimals' => 2, 'is_active' => true, 'sort_order' => 1,
    ]);
    DisplayCurrency::query()->create([
        'code' => 'GBP', 'symbol' => '£', 'name' => 'British Pound',
        'units_per_naira' => '0.00051', 'decimals' => 2, 'is_active' => false, 'sort_order' => 2,
    ]);

    LocalePreference::forgetCurrencyCache();
}

function storefront(string $path = '/'): string
{
    return 'http://'.strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)).$path;
}

it('defaults to English and naira', function () {
    seedCurrencies();

    $this->get(storefront())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('i18n.locale', 'en')
            ->where('i18n.currency.code', 'NGN')
            // json_encode drops the zero fraction, so 1.0 arrives as 1.
            ->where('i18n.currency.rate', 1)
            ->where('i18n.currency.isBase', true));
});

it('switches language and serves translated strings', function () {
    seedCurrencies();

    $this->post(storefront('/locale'), ['locale' => 'ha'])->assertRedirect();

    $this->withCookie(LocalePreference::LOCALE_COOKIE, 'ha')
        ->get(storefront())
        ->assertInertia(fn ($page) => $page
            ->where('i18n.locale', 'ha')
            // A real Hausa string, not the English fallback.
            ->where('i18n.translations.Cart', 'Kwando'));
});

it('falls back to English for a string a language has not translated yet', function () {
    seedCurrencies();

    // Every locale is merged over English, so an untranslated key renders
    // readable English rather than a raw key.
    $this->withCookie(LocalePreference::LOCALE_COOKIE, 'fr')
        ->get(storefront())
        ->assertInertia(fn ($page) => $page
            ->where('i18n.locale', 'fr')
            ->where('i18n.translations.Cart', 'Panier')
            ->has('i18n.translations.Categories'));
});

it('switches the display currency and exposes its rate', function () {
    seedCurrencies();

    $this->withCookie(LocalePreference::CURRENCY_COOKIE, 'USD')
        ->get(storefront())
        ->assertInertia(fn ($page) => $page
            ->where('i18n.currency.code', 'USD')
            ->where('i18n.currency.rate', 0.00065)
            ->where('i18n.currency.decimals', 2)
            ->where('i18n.currency.isBase', false));
});

it('offers only currencies staff keep a rate for', function () {
    seedCurrencies();

    $this->get(storefront())->assertInertia(function ($page) {
        $codes = collect($page->toArray()['props']['i18n']['currencies'])->pluck('code');

        expect($codes)->toContain('NGN', 'USD')
            ->and($codes)->not->toContain('GBP'); // deactivated
    });
});

it('refuses a currency that is not active', function () {
    seedCurrencies();

    $this->post(storefront('/locale'), ['currency' => 'GBP'])
        ->assertSessionHasErrors('currency');
});

it('drops back to naira when the chosen currency is switched off later', function () {
    seedCurrencies();

    // A shopper browsing in pounds, which staff then deactivate.
    DisplayCurrency::query()->where('code', 'USD')->update(['is_active' => false]);
    LocalePreference::forgetCurrencyCache();

    $this->withCookie(LocalePreference::CURRENCY_COOKIE, 'USD')
        ->get(storefront())
        ->assertInertia(fn ($page) => $page->where('i18n.currency.code', 'NGN'));
});

it('honours the browser Accept-Language header when no choice has been made', function () {
    seedCurrencies();

    $this->withHeader('Accept-Language', 'yo-NG,yo;q=0.9,en;q=0.5')
        ->get(storefront())
        ->assertInertia(fn ($page) => $page->where('i18n.locale', 'yo'));
});

it('ignores an Accept-Language we have no translations for', function () {
    seedCurrencies();

    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->get(storefront())
        ->assertInertia(fn ($page) => $page->where('i18n.locale', 'en'));
});

it('prefers the saved choice over the browser header', function () {
    seedCurrencies();

    $this->withCookie(LocalePreference::LOCALE_COOKIE, 'ig')
        ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get(storefront())
        ->assertInertia(fn ($page) => $page->where('i18n.locale', 'ig'));
});

it('leaves the staff portals in English naira', function () {
    seedCurrencies();

    // Admin and vendor screens read the ledger, which is denominated in naira;
    // a converted figure on a payout would be actively misleading.
    $this->get('http://'.strtolower((string) config('app.admin_domain')).'/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('i18n', null));

    $this->get('http://'.strtolower((string) config('app.vendor_domain')).'/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('i18n', null));
});
