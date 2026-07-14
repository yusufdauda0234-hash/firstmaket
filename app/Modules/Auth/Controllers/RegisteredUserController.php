<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Requests\RegisterUserRequest;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Minimal customer self-registration for Sprint 1 foundation. OTP phone
 * verification, email verification, and BVN/NIN hooks are Sprint 2 scope
 * (docs/firstmarket_Implementation_Plan.md) and extend this flow rather
 * than replace it.
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterUserRequest $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $user = User::query()->create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'phone' => $request->string('phone'),
            'password' => Hash::make($request->string('password')->value()),
            'user_type' => UserType::Customer,
            'status' => UserStatus::Active,
        ]);

        $user->assignRole('Customer');

        event(new Registered($user));

        $auditLogger->log(actor: $user, subject: $user, action: 'auth.register');

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
