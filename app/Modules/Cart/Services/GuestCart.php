<?php

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The cart of a shopper who has not signed in yet.
 *
 * It lives in the carts table like everyone else's, found by a random token
 * in a long-lived cookie rather than by user id. This is how large
 * marketplaces do it — Amazon and AliExpress both keep a signed-out cart
 * server-side against a device cookie that outlives the session by months,
 * Shopify by a fortnight — and it is the reason a cart you filled yesterday
 * is still there today.
 *
 * A session would not do: SESSION_LIFETIME is two hours idle, so the cart
 * would quietly empty itself over lunch. Keeping guest carts in the same
 * table as account carts also means one storage path, so CartService cannot
 * drift between the two.
 *
 * The row is only created when something is actually added, so a crawler
 * that never adds to a cart never writes a row or receives a cookie.
 */
class GuestCart
{
    public const COOKIE = 'firstmaket_cart';

    /** Roughly 90 days, in minutes — the long end of common practice. */
    private const LIFETIME_MINUTES = 60 * 24 * 90;

    /** Set once per request so two adds in one request share a token. */
    private ?string $issuedToken = null;

    public function __construct(private readonly Request $request) {}

    /**
     * The guest's cart, or null when they have never added anything and
     * $create is false — reading must not mint cookies for every visitor.
     */
    public function cart(bool $create = false): ?Cart
    {
        $token = $this->token($create);

        if ($token === null) {
            return null;
        }

        if (! $create) {
            return Cart::query()->where('token', $token)->first();
        }

        $cart = Cart::query()->firstOrCreate(['token' => $token], ['user_id' => null]);

        // Re-issue on every write so an active shopper's cookie keeps
        // sliding forward instead of expiring on a fixed date.
        $this->queueCookie($token);

        return $cart;
    }

    /** Drops the cookie once the cart has been merged into an account. */
    public function forget(): void
    {
        $this->issuedToken = null;

        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    private function token(bool $create): ?string
    {
        $existing = $this->issuedToken ?? $this->request->cookie(self::COOKIE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        if (! $create) {
            return null;
        }

        return $this->issuedToken = Str::random(48);
    }

    private function queueCookie(string $token): void
    {
        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: self::LIFETIME_MINUTES,
            // httpOnly: nothing in the browser needs to read this, and it
            // identifies a cart, so keep it away from any injected script.
            httpOnly: true,
            sameSite: 'lax',
        ));
    }
}
