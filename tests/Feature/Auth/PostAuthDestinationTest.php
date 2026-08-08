<?php

use App\Models\User;
use App\Modules\Auth\Controllers\AuthFlowController;
use App\Shared\Enums\OtpChannel;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function shopper(): User
{
    $user = User::factory()->create([
        'email' => 'shopper@example.test',
        'password' => Hash::make('password'),
        'user_type' => UserType::Customer,
    ]);
    $user->assignRole('Customer');

    return $user;
}

/**
 * The session state AuthFlowController leaves behind once an OTP is proven.
 *
 * `verified_until` matters: registration refuses to proceed without an
 * unexpired stamp, so leaving it out makes every case here fail on validation
 * rather than on the redirect being tested.
 */
function verifiedRegistration(string $identifier, OtpChannel $channel = OtpChannel::Email): array
{
    return [AuthFlowController::VERIFIED_SESSION_KEY => [
        'identifier' => $identifier,
        'channel' => $channel->value,
        'verified_until' => now()->addMinutes(15)->getTimestamp(),
    ]];
}

// ── Signing in ──────────────────────────────────────────────────────────

it('sends a shopper on to checkout after signing in', function () {
    shopper();

    $this->post('/login', [
        'identifier' => 'shopper@example.test',
        'password' => 'password',
        'redirect' => '/cart/checkout',
    ])->assertRedirect('/cart/checkout');
});

it('falls back to home when signing in from nowhere in particular', function () {
    shopper();

    $this->post('/login', [
        'identifier' => 'shopper@example.test',
        'password' => 'password',
    ])->assertRedirect(route('home'));
});

// ── Registering ─────────────────────────────────────────────────────────

it('sends a new shopper on to checkout after registering', function () {
    /*
     * The bug this covers: registering always landed on the dashboard, so
     * somebody who pressed Checkout with a full cart, was shown the sign-up
     * modal and created an account was then dropped somewhere else entirely
     * and had to find their way back to the checkout they asked for.
     */
    $this->withSession(verifiedRegistration('new@example.test'))
        ->post('/register', [
            'name' => 'NEW SHOPPER',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'redirect' => '/cart/checkout',
        ])
        ->assertRedirect('/cart/checkout');

    expect(User::query()->where('email', 'new@example.test')->exists())->toBeTrue();
});

it('still lands on the dashboard when registering from nowhere in particular', function () {
    $this->withSession(verifiedRegistration('plain@example.test'))
        ->post('/register', [
            'name' => 'PLAIN SHOPPER',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertRedirect(route('dashboard'));
});

// ── The guard around where it will send people ───────────────────────────

it('refuses to bounce a new account off-site', function (string $hostile) {
    // An open redirect on a signup form is a phishing primitive: a link that
    // genuinely signs you into FirstMaket and then hands you to somebody else.
    $this->withSession(verifiedRegistration('guard@example.test'))
        ->post('/register', [
            'name' => 'GUARD',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'redirect' => $hostile,
        ])
        ->assertRedirect(route('dashboard'));
})->with([
    'absolute' => 'https://evil.test/steal',
    'protocol relative' => '//evil.test/steal',
    'not a path' => 'evil.test',
]);

it('refuses to bounce a sign-in off-site', function () {
    shopper();

    $this->post('/login', [
        'identifier' => 'shopper@example.test',
        'password' => 'password',
        'redirect' => 'https://evil.test/steal',
    ])->assertRedirect(route('home'));
});

it('does not send anyone back to an auth page', function () {
    shopper();

    // Landing on /login while already signed in is a loop.
    $this->post('/login', [
        'identifier' => 'shopper@example.test',
        'password' => 'password',
        'redirect' => '/login',
    ])->assertRedirect(route('home'));
});
