<?php

namespace App\Shared\Middleware;

use App\Shared\Enums\VendorStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nothing in the Vendor Center works until the vendor is approved.
 *
 * Approval used to be checked inside individual controllers — product
 * management refused it, orders and earnings did not — so a pending vendor
 * signed in to a full navigation and found out which pages worked by clicking
 * them. That reads as a broken site rather than a queue they are waiting in.
 *
 * The dashboard is deliberately still reachable: it is where they are told
 * what is happening, and a portal that redirects every route including the
 * one explaining why would be a loop.
 */
class EnsureVendorApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user()?->vendorProfile;

        if ($profile !== null && $profile->status === VendorStatus::Approved) {
            return $next($request);
        }

        // A write attempt gets a refusal rather than a redirect: a form post
        // that quietly lands on the dashboard looks like it worked.
        if (! $request->isMethod('GET')) {
            abort(403, self::reasonFor($profile?->status));
        }

        return redirect()
            ->route('vendor.dashboard')
            ->with('error', self::reasonFor($profile?->status));
    }

    /**
     * Why they cannot get in, in words they can act on.
     *
     * Each status is a different situation — waiting, refused, or stopped —
     * and telling all three "not approved" leaves a rejected vendor waiting
     * for an email that is never coming.
     */
    public static function reasonFor(?VendorStatus $status): string
    {
        return match ($status) {
            VendorStatus::Pending => 'Your account is still being reviewed. You can set up your shop once it is approved.',
            VendorStatus::Rejected => 'Your application was not approved. Check the reason on your dashboard.',
            VendorStatus::Suspended => 'Your account is suspended, so selling is paused. Contact support to sort it out.',
            VendorStatus::Banned => 'This account can no longer sell on FirstMaket.',
            default => 'Your seller account is not set up yet.',
        };
    }
}
