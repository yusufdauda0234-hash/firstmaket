<?php

namespace App\Modules\Auth\Requests;

use App\Modules\Auth\Services\AuthIdentifier;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use App\Shared\Security\AdminDomain;
use App\Shared\Security\VendorDomain;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password login with an email-or-phone identifier (Sprint 2 Addendum). The
 * admin portal still posts `email`; the customer modal posts `identifier` —
 * both are accepted.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:identifier', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $identifier = AuthIdentifier::parse($this->rawIdentifier());

        if (! Auth::attempt([$identifier->column() => $identifier->value, 'password' => $this->string('password')->value()], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            $message = trans('auth.failed');

            throw ValidationException::withMessages(['identifier' => $message, 'email' => $message]);
        }

        $user = Auth::user();

        if (in_array($user->status, [UserStatus::Suspended, UserStatus::Banned], true)) {
            Auth::guard('web')->logout();

            $message = 'Your account has been '.$user->status->value.'. Contact support for assistance.';

            throw ValidationException::withMessages(['identifier' => $message, 'email' => $message]);
        }

        // Wrong-portal guard: staff sign in only on the admin subdomain,
        // vendors only on the Vendor Center or main site, customers only on
        // the main site. Failing loudly with a clear message beats silently
        // landing someone on a dashboard that will 403 every click.
        $isAdminPortal = AdminDomain::matches($this);
        $isVendorPortal = VendorDomain::matches($this);
        $isStaff = $user->user_type === UserType::Staff;
        $isVendor = $user->user_type === UserType::Vendor;

        if ($isAdminPortal && ! $isStaff) {
            Auth::guard('web')->logout();

            $message = 'This sign-in page is for FirstMarketstaff only. Customer and vendor accounts sign in on the main FirstMarketsite.';

            throw ValidationException::withMessages(['identifier' => $message, 'email' => $message]);
        }

        if ($isVendorPortal && ! $isVendor) {
            Auth::guard('web')->logout();

            $message = 'This sign-in page is for FirstMarketvendors only. Shoppers sign in on the main FirstMarketsite — or apply there to become a vendor.';

            throw ValidationException::withMessages(['identifier' => $message, 'email' => $message]);
        }

        if (! $isAdminPortal && ! $isVendorPortal && $isStaff) {
            Auth::guard('web')->logout();

            $message = 'Staff accounts sign in through the staff portal, not the customer site. Please use your admin portal address.';

            throw ValidationException::withMessages(['identifier' => $message, 'email' => $message]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        $message = trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => ceil($seconds / 60),
        ]);

        throw ValidationException::withMessages(['identifier' => $message, 'email' => $message]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->rawIdentifier()).'|'.$this->ip());
    }

    private function rawIdentifier(): string
    {
        $identifier = $this->string('identifier')->value();

        return $identifier !== '' ? $identifier : $this->string('email')->value();
    }
}
