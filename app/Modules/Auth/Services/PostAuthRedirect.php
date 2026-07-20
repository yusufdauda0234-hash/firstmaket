<?php

namespace App\Modules\Auth\Services;

use Illuminate\Http\Request;

/**
 * Where a customer lands after authenticating (Section 3 behavior): big
 * marketplaces don't shove you onto a dashboard after login — you stay on the
 * page you were using. So we prefer an explicit local `redirect` (the page the
 * sign-in modal was opened from), and fall back to the home page. The auth
 * guard's own "intended" URL still wins for gated pages via
 * redirect()->intended().
 */
class PostAuthRedirect
{
    public static function customer(Request $request): string
    {
        $requested = (string) $request->input('redirect', '');

        // Only honor safe, same-site relative paths — never an absolute or
        // protocol-relative URL (open-redirect guard) — and never bounce back
        // onto an auth page (which would loop).
        $isAuthPage = preg_match('#^/(login|register|vendor/register)\b#', $requested) === 1;

        if ($requested !== '' && str_starts_with($requested, '/') && ! str_starts_with($requested, '//') && ! $isAuthPage) {
            return $requested;
        }

        return route('home');
    }
}
