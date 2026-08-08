<?php

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Services\LocalePreference;
use App\Shared\Enums\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stores the shopper's language / currency / ship-to choice.
 *
 * A full request rather than a client-side toggle because translation happens
 * server-side: the next render has to come back in the new language, with
 * prices already carrying the new currency's rate.
 */
class LocaleController
{
    public function __construct(private readonly LocalePreference $preference) {}

    public function update(Request $request): RedirectResponse
    {
        $activeCodes = $this->preference->activeCurrencies()->pluck('code')->all();

        $data = $request->validate([
            'locale' => ['nullable', Rule::enum(Locale::class)],
            'currency' => ['nullable', 'string', Rule::in($activeCodes)],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ], [
            'currency.in' => 'That currency is not available right now.',
        ]);

        $response = back(fallback: route('home'));

        foreach ($this->preference->cookiesFor(
            Locale::tryFromValue($data['locale'] ?? null),
            $data['currency'] ?? null,
            $data['country'] ?? null,
        ) as $cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }
}
