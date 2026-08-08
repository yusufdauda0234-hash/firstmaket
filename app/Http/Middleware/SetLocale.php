<?php

namespace App\Http\Middleware;

use App\Modules\Catalog\Services\LocalePreference;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the shopper's chosen language to the request before anything renders,
 * so server-side strings (validation messages, mail, anything through __())
 * come back in the same language as the page.
 */
class SetLocale
{
    public function __construct(private readonly LocalePreference $preference) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->preference->locale($request)->value);

        return $next($request);
    }
}
