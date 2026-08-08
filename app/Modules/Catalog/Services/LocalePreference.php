<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\DisplayCurrency;
use App\Shared\Enums\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;

/**
 * Resolves and persists the shopper's language, display currency, and
 * ship-to country.
 *
 * Cookies rather than the session so the choice survives logout and an
 * expired session — a Yoruba speaker should not be dropped back into English
 * because they were away for an hour. Guests and signed-in shoppers use the
 * same mechanism, so nothing is lost when a guest registers.
 */
final class LocalePreference
{
    public const LOCALE_COOKIE = 'fm_locale';

    public const CURRENCY_COOKIE = 'fm_currency';

    public const COUNTRY_COOKIE = 'fm_country';

    private const YEAR_IN_MINUTES = 525600;

    private const CURRENCY_CACHE_KEY = 'display_currencies.active';

    public function locale(Request $request): Locale
    {
        return Locale::tryFromValue($request->cookie(self::LOCALE_COOKIE))
            ?? $this->fromAcceptLanguage($request)
            ?? Locale::default();
    }

    public function countryCode(Request $request): string
    {
        $code = strtoupper((string) $request->cookie(self::COUNTRY_COOKIE));

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : 'NG';
    }

    /**
     * The chosen currency, falling back to the naira whenever the cookie names
     * one that staff have since deactivated or deleted — never leaving a
     * shopper looking at prices in a currency we no longer maintain a rate for.
     */
    public function currency(Request $request): DisplayCurrency
    {
        $code = strtoupper((string) $request->cookie(self::CURRENCY_COOKIE));
        $active = $this->activeCurrencies();

        return $active->firstWhere('code', $code)
            ?? $active->firstWhere('code', 'NGN')
            ?? $this->nairaFallback();
    }

    /** @return Collection<int, DisplayCurrency> */
    public function activeCurrencies()
    {
        return Cache::remember(
            self::CURRENCY_CACHE_KEY,
            now()->addMinutes(10),
            fn () => DisplayCurrency::query()->active()->get(),
        );
    }

    public static function forgetCurrencyCache(): void
    {
        Cache::forget(self::CURRENCY_CACHE_KEY);
    }

    /** @return array<string, \Symfony\Component\HttpFoundation\Cookie> */
    public function cookiesFor(?Locale $locale, ?string $currencyCode, ?string $countryCode): array
    {
        $cookies = [];

        if ($locale !== null) {
            $cookies[] = Cookie::make(self::LOCALE_COOKIE, $locale->value, self::YEAR_IN_MINUTES);
        }

        if ($currencyCode !== null) {
            $cookies[] = Cookie::make(self::CURRENCY_COOKIE, strtoupper($currencyCode), self::YEAR_IN_MINUTES);
        }

        if ($countryCode !== null) {
            $cookies[] = Cookie::make(self::COUNTRY_COOKIE, strtoupper($countryCode), self::YEAR_IN_MINUTES);
        }

        return $cookies;
    }

    /**
     * First supported language the browser asks for, honouring q-weights.
     * A Hausa speaker whose phone is set to Hausa should land on Hausa without
     * touching the picker.
     */
    private function fromAcceptLanguage(Request $request): ?Locale
    {
        $header = (string) $request->header('Accept-Language');

        if ($header === '') {
            return null;
        }

        $ranked = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $tag = strtolower(trim($bits[0]));
            $quality = isset($bits[1]) ? (float) $bits[1] : 1.0;

            if ($tag === '') {
                continue;
            }

            // "yo-NG" and "yo" both map to Yoruba.
            $primary = explode('-', $tag)[0];
            $ranked[] = [$primary, $quality];
        }

        usort($ranked, fn ($a, $b) => $b[1] <=> $a[1]);

        foreach ($ranked as [$primary]) {
            if ($locale = Locale::tryFrom($primary)) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Used only if the currency table has not been seeded, so the storefront
     * still renders naira prices instead of failing.
     */
    private function nairaFallback(): DisplayCurrency
    {
        return new DisplayCurrency([
            'code' => 'NGN',
            'symbol' => '₦',
            'name' => 'Nigerian Naira',
            'units_per_naira' => '1',
            'decimals' => 0,
            'is_active' => true,
        ]);
    }
}
