<?php

namespace App\Modules\Cart\Listeners;

use App\Models\User;
use App\Modules\Cart\Services\CartService;
use Illuminate\Auth\Events\Login;

/**
 * Carries a guest's session cart into their real cart the moment they sign
 * in, whatever the entry path — password, OTP or social — because every one
 * of them ends in Illuminate\Auth\Events\Login. Without this, a shopper who
 * fills a cart, then signs in to check out, would watch it empty itself.
 *
 * Registered in App\Providers\AppServiceProvider.
 */
class MergeGuestCartOnLogin
{
    public function __construct(private readonly CartService $cartService) {}

    public function handle(Login $event): void
    {
        // Staff and vendors sign in on their own subdomains, where there is
        // no storefront cart to merge.
        if (! $event->user instanceof User) {
            return;
        }

        $this->cartService->mergeGuestCartInto($event->user);
    }
}
