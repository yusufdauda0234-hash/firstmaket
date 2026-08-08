<?php

use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Notifications\VendorPasswordResetNotification;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function resetStaff(string $role = 'Administrator'): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function vendorNeedingReset(): VendorProfile
{
    $user = User::factory()->create([
        'user_type' => UserType::Vendor,
        'email' => 'seller@example.test',
        'password' => Hash::make('The-Old-Password9'),
    ]);

    return VendorProfile::factory()->create([
        'user_id' => $user->id,
        'status' => VendorStatus::Approved,
    ]);
}

function adminResetUrl(VendorProfile $profile): string
{
    return 'http://'.strtolower((string) config('app.admin_domain'))."/vendors/{$profile->uuid}/password-reset";
}

function vendorHost(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).'/'.ltrim($path, '/');
}

// ── Admin sends the link ──────────────────────────────────────────────────

it('emails the vendor a link, not a code', function () {
    Notification::fake();
    $profile = vendorNeedingReset();

    $this->actingAs(resetStaff())
        ->post(adminResetUrl($profile))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($profile->user, VendorPasswordResetNotification::class);
});

it('points the link at the Vendor Center, not the customer site', function () {
    Notification::fake();
    $profile = vendorNeedingReset();

    $this->actingAs(resetStaff())->post(adminResetUrl($profile));

    Notification::assertSentTo($profile->user, VendorPasswordResetNotification::class,
        function (VendorPasswordResetNotification $notification) use ($profile) {
            // The whole point of a dedicated notification: the built-in one
            // resolves route('password.reset') on the wrong host.
            $body = $notification->toMail($profile->user)->render();

            return stripos($body, (string) config('app.vendor_domain')) !== false
                && str_contains($body, '/reset-password/');
        });
});

it('actually renders the email rather than only queueing it', function () {
    // Mail::fake, not Notification::fake — a Notification fake never builds the
    // message, which is how the missing password.reset route reached production.
    Mail::fake();
    vendorNeedingReset();

    $this->actingAs(resetStaff())
        ->post(adminResetUrl(VendorProfile::query()->first()))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('never sets or reveals the password itself', function () {
    Notification::fake();
    $profile = vendorNeedingReset();
    $before = $profile->user->password;

    $this->actingAs(resetStaff())->post(adminResetUrl($profile));

    // Staff trigger it; the vendor still chooses the password.
    expect($profile->user->fresh()->password)->toBe($before);
});

it('blocks staff who cannot manage vendors', function () {
    Notification::fake();
    $profile = vendorNeedingReset();

    $this->actingAs(resetStaff('Support Agent'))
        ->post(adminResetUrl($profile))
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('writes an audit entry naming the staff member', function () {
    Notification::fake();
    $profile = vendorNeedingReset();
    $staff = resetStaff();

    $this->actingAs($staff)->post(adminResetUrl($profile));

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $staff->id,
        'action' => 'vendor.password_reset_sent',
    ]);
});

// ── The vendor follows it ─────────────────────────────────────────────────

it('opens the set-password form on the Vendor Center', function () {
    $profile = vendorNeedingReset();
    $token = Password::broker()->createToken($profile->user);

    $this->get(vendorHost("reset-password/{$token}?email=seller@example.test"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/VendorResetPassword')
            ->where('email', 'seller@example.test')
            ->where('token', $token));
});

it('sets the new password and sends them to sign in', function () {
    $profile = vendorNeedingReset();
    $token = Password::broker()->createToken($profile->user);

    $this->post(vendorHost('reset-password'), [
        'token' => $token,
        'email' => 'seller@example.test',
        'password' => 'Brand-New-Password9',
        'password_confirmation' => 'Brand-New-Password9',
    ])->assertRedirect(route('vendor.login'));

    expect(Hash::check('Brand-New-Password9', $profile->user->fresh()->password))->toBeTrue();
});

it('burns the link after one use', function () {
    $profile = vendorNeedingReset();
    $token = Password::broker()->createToken($profile->user);

    $payload = [
        'token' => $token,
        'email' => 'seller@example.test',
        'password' => 'Brand-New-Password9',
        'password_confirmation' => 'Brand-New-Password9',
    ];

    $this->post(vendorHost('reset-password'), $payload)->assertRedirect(route('vendor.login'));

    // A link forwarded or left in an inbox must not keep working.
    $this->post(vendorHost('reset-password'), [
        ...$payload,
        'password' => 'Second-Attempt-Password9',
        'password_confirmation' => 'Second-Attempt-Password9',
    ])
        ->assertSessionHasErrors('email');

    expect(Hash::check('Brand-New-Password9', $profile->user->fresh()->password))->toBeTrue();
});

it('rejects a tampered token', function () {
    $profile = vendorNeedingReset();
    Password::broker()->createToken($profile->user);

    $this->post(vendorHost('reset-password'), [
        'token' => 'not-a-real-token',
        'email' => 'seller@example.test',
        'password' => 'Brand-New-Password9',
        'password_confirmation' => 'Brand-New-Password9',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('The-Old-Password9', $profile->user->fresh()->password))->toBeTrue();
});

it('requires the confirmation to match', function () {
    $profile = vendorNeedingReset();
    $token = Password::broker()->createToken($profile->user);

    $this->post(vendorHost('reset-password'), [
        'token' => $token,
        'email' => 'seller@example.test',
        'password' => 'Brand-New-Password9',
        'password_confirmation' => 'something-else-entirely',
    ])->assertSessionHasErrors('password');
});

it('will not let a vendor link reset a non-vendor account', function () {
    $customer = User::factory()->create(['email' => 'shopper@example.test']);
    $token = Password::broker()->createToken($customer);

    // The Vendor Center form is scoped to vendors, so a token minted for a
    // customer cannot be redeemed here even with a valid token.
    $this->post(vendorHost('reset-password'), [
        'token' => $token,
        'email' => 'shopper@example.test',
        'password' => 'Brand-New-Password9',
        'password_confirmation' => 'Brand-New-Password9',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('Brand-New-Password9', $customer->fresh()->password))->toBeFalse();
});

it('is not reachable on the customer site', function () {
    $profile = vendorNeedingReset();
    $token = Password::broker()->createToken($profile->user);

    $this->get('http://'.strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))
        ."/reset-password/{$token}")->assertNotFound();
});
