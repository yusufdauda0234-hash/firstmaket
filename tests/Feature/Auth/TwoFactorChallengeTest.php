<?php

use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Modules\Auth\Controllers\TwoFactorChallengeController;
use App\Shared\Enums\UserType;
use App\Shared\Security\TwoFactorCodes;
use App\Shared\Security\TwoFactorDevices;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    RateLimiter::clear('two-factor:1');
});

function twoFactorUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/'.ltrim($path, '/');
}

/** An enrolled Administrator, plus the shared secret so tests can produce codes. */
function enrolledAdmin(string $password = 'correct-horse'): array
{
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = User::factory()->create([
        'user_type' => UserType::Staff,
        'password' => bcrypt($password),
    ]);
    $user->assignRole('Administrator');
    $user->forceFill([
        'two_factor_secret' => Crypt::encryptString($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return [$user, $secret];
}

function currentTotp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

// ── The gap this closes ──────────────────────────────────────────────────

it('does not sign in on password alone once 2FA is enrolled', function () {
    [$user] = enrolledAdmin();

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect(route('admin.two-factor.challenge'));

    // The whole point: a correct password must not be a session.
    $this->assertGuest();
    expect(session(TwoFactorChallengeController::PENDING_USER))->toBe($user->id);
});

it('signs in once the authenticator code is verified', function () {
    [$user, $secret] = enrolledAdmin();

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);

    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => currentTotp($secret)])
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session(TwoFactorChallengeController::PENDING_USER))->toBeNull();
});

it('rejects a wrong code and leaves the visitor a guest', function () {
    [$user] = enrolledAdmin();

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);

    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('refuses the challenge page with no pending sign-in', function () {
    $this->get(twoFactorUrl('two-factor/challenge'))->assertRedirect(route('admin.login'));
});

it('cannot be completed by naming a different account', function () {
    [$victim, $victimSecret] = enrolledAdmin();

    // No pending sign-in at all: a valid code must not be usable on its own.
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => currentTotp($victimSecret)])
        ->assertRedirect(route('admin.login'));

    $this->assertGuest();
    expect($victim->fresh()->last_login_at)->toBeNull();
});

// ── Replay and brute force ────────────────────────────────────────────────

it('will not accept the same code twice', function () {
    [$user, $secret] = enrolledAdmin();
    $code = currentTotp($secret);

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => $code]);
    $this->assertAuthenticatedAs($user);

    $this->post(twoFactorUrl('logout'));

    // A code shoulder-surfed or captured mid-flight stays valid for its window;
    // replaying it must fail.
    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => $code])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('records the replay watermark in TOTP windows, not raw seconds', function () {
    [$user, $secret] = enrolledAdmin();

    app(TwoFactorCodes::class)->verifyTotp($user, currentTotp($secret));

    // google2fa counts time in 30-second windows. Storing its return value as
    // if it were unix seconds made every later code look older than the
    // watermark, which locked the account out of TOTP after one sign-in — and
    // no test noticed, because the replay test then passed for the wrong
    // reason.
    $storedWindow = intdiv((int) $user->fresh()->two_factor_last_used_at->timestamp, 30);

    expect($storedWindow)->toBe(app(Google2FA::class)->getTimestamp());
});

it('still accepts a fresh code once the stored window has passed', function () {
    [$user, $secret] = enrolledAdmin();

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => currentTotp($secret)]);
    $this->assertAuthenticatedAs($user);
    $this->post(twoFactorUrl('logout'));

    // Stand in for time passing. google2fa reads real time(), which Laravel's
    // travel() cannot move, so the watermark is aged instead — the same state
    // the account would be in on the next real sign-in.
    $user->forceFill(['two_factor_last_used_at' => now()->subMinutes(5)])->save();

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => currentTotp($secret)])
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('locks the challenge after five wrong codes', function () {
    [$user] = enrolledAdmin();

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);

    for ($i = 0; $i < 5; $i++) {
        $this->post(twoFactorUrl('two-factor/challenge'), ['code' => '000000']);
    }

    // A six-digit code is guessable without a limit here.
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect(RateLimiter::tooManyAttempts('two-factor:'.$user->id, 5))->toBeTrue();
});

// ── Recovery codes ────────────────────────────────────────────────────────

it('accepts a recovery code and burns it', function () {
    [$user] = enrolledAdmin();
    $codes = app(TwoFactorCodes::class)->regenerateRecoveryCodes($user);

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => $codes[0]])
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(app(TwoFactorCodes::class)->remainingRecoveryCodes($user->fresh()))->toBe(7);
});

it('will not accept the same recovery code twice', function () {
    [$user] = enrolledAdmin();
    $codes = app(TwoFactorCodes::class)->regenerateRecoveryCodes($user);

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => $codes[0]]);
    $this->post(twoFactorUrl('logout'));

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), ['code' => $codes[0]])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('stores recovery codes hashed, never readable', function () {
    [$user] = enrolledAdmin();
    $codes = app(TwoFactorCodes::class)->regenerateRecoveryCodes($user);

    $stored = Crypt::decryptString($user->fresh()->two_factor_recovery_codes);

    // A database leak must not hand over usable codes.
    foreach ($codes as $code) {
        expect($stored)->not->toContain($code);
    }
    expect(json_decode($stored, true))->toHaveCount(8);
});

it('does not trust the device when a recovery code was used', function () {
    [$user] = enrolledAdmin();
    $codes = app(TwoFactorCodes::class)->regenerateRecoveryCodes($user);

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), [
        'code' => $codes[0],
        'remember_device' => true,
    ]);

    // A recovery code means the authenticator is unavailable — not a moment to
    // hand out a 30-day bypass.
    expect(TwoFactorDevice::query()->where('user_id', $user->id)->count())->toBe(0);
});

// ── Trusted devices ───────────────────────────────────────────────────────

it('remembers a trusted device and skips the challenge next time', function () {
    [$user, $secret] = enrolledAdmin();

    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post(twoFactorUrl('two-factor/challenge'), [
        'code' => currentTotp($secret),
        'remember_device' => true,
    ]);

    $device = TwoFactorDevice::query()->where('user_id', $user->id)->first();
    expect($device)->not->toBeNull()
        ->and($device->expires_at->isAfter(now()->addDays(29)))->toBeTrue();

    $this->post(twoFactorUrl('logout'));

    // The plaintext token lives in the cookie; only its hash is stored.
    $this->assertNotSame($device->token_hash, '');
});

it('signs a trusted device straight in with no code at all', function () {
    [$user] = enrolledAdmin();

    // Seeded directly with a known token because the response cookie is
    // encrypted by the time a test can read it, and handing that back would be
    // encrypted twice.
    TwoFactorDevice::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'trusted-token'),
        'expires_at' => now()->addDays(30),
    ]);

    $this->withCookie(TwoFactorDevices::COOKIE, 'trusted-token')
        ->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session(TwoFactorChallengeController::PENDING_USER))->toBeNull();
});

it('stamps last_used_at when a trusted device is used', function () {
    [$user] = enrolledAdmin();

    TwoFactorDevice::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'trusted-token'),
        'expires_at' => now()->addDays(30),
        'last_used_at' => null,
    ]);

    $this->withCookie(TwoFactorDevices::COOKIE, 'trusted-token')
        ->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse']);

    // So a stale device is identifiable when someone reviews the list.
    expect(TwoFactorDevice::query()->where('user_id', $user->id)->first()->last_used_at)->not->toBeNull();
});

it('stores only a hash of the device token', function () {
    [$user] = enrolledAdmin();
    $request = request();
    $cookie = app(TwoFactorDevices::class)->remember($user, $request);

    $device = TwoFactorDevice::query()->where('user_id', $user->id)->firstOrFail();

    expect($device->token_hash)->not->toBe($cookie->getValue())
        ->and($device->token_hash)->toBe(hash('sha256', $cookie->getValue()));
});

it('ignores an expired device and asks for a code again', function () {
    [$user] = enrolledAdmin();

    TwoFactorDevice::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'stale-token'),
        'expires_at' => now()->subDay(),
    ]);

    $this->withCookie(TwoFactorDevices::COOKIE, 'stale-token')
        ->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect(route('admin.two-factor.challenge'));

    $this->assertGuest();
});

it('does not let one user\'s device token skip another user\'s challenge', function () {
    [$alice] = enrolledAdmin();
    [$bob] = enrolledAdmin('bobs-password');

    $token = 'shared-token';
    TwoFactorDevice::query()->create([
        'user_id' => $alice->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(30),
    ]);

    $this->withCookie(TwoFactorDevices::COOKIE, $token)
        ->post(twoFactorUrl('login'), ['email' => $bob->email, 'password' => 'bobs-password'])
        ->assertRedirect(route('admin.two-factor.challenge'));

    $this->assertGuest();
});

// ── Who is and is not challenged ──────────────────────────────────────────

it('does not challenge staff who have not enrolled', function () {
    $user = User::factory()->create(['user_type' => UserType::Staff, 'password' => bcrypt('correct-horse')]);
    $user->assignRole('Administrator');

    // They are pushed to enrollment by EnsureTwoFactorEnrolled instead.
    $this->post(twoFactorUrl('login'), ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('does not challenge a customer on the storefront', function () {
    $customer = User::factory()->create(['password' => bcrypt('correct-horse')]);
    $customer->forceFill(['two_factor_confirmed_at' => now()])->save();

    $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    // Customers have no 2FA setup screen, so a challenge would be a dead end.
    $this->post('http://'.$host.'/login', ['identifier' => $customer->email, 'password' => 'correct-horse'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($customer);
});

it('drops every trusted device when 2FA is re-enrolled', function () {
    [$user, $secret] = enrolledAdmin();

    TwoFactorDevice::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'old-laptop'),
        'expires_at' => now()->addDays(30),
    ]);

    $this->actingAs($user)
        ->post(twoFactorUrl('two-factor/confirm'), ['code' => currentTotp($secret)])
        ->assertRedirect(route('admin.two-factor.recovery-codes'));

    // Re-enrolling usually means a lost phone; old bypasses must stop working.
    expect(TwoFactorDevice::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('shows recovery codes once and not again on refresh', function () {
    [$user, $secret] = enrolledAdmin();

    $this->actingAs($user)->post(twoFactorUrl('two-factor/confirm'), ['code' => currentTotp($secret)]);

    $this->actingAs($user)->get(twoFactorUrl('two-factor/recovery-codes'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Auth/RecoveryCodes')->has('codes', 8));

    // Flash-only: hashed at rest, so there is nothing to show a second time.
    $this->actingAs($user)->get(twoFactorUrl('two-factor/recovery-codes'))
        ->assertRedirect(route('admin.dashboard'));
});
