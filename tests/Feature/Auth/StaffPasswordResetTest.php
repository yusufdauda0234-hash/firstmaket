<?php

use App\Models\User;
use App\Modules\Admin\Notifications\StaffPasswordResetNotification;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Staff password recovery.
 *
 * Staff previously had none. A new joiner was emailed a six-digit code with
 * nowhere on the portal to type it, and anyone who forgot their password had
 * to ask a colleague to intervene. Both are a link now.
 *
 * The security properties are what these tests are really for: a token from
 * one portal must never open an account on another, a suspended account must
 * not be able to let itself back in, and the form must not reveal which
 * addresses belong to FirstMaket staff.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function staffMember(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'user_type' => UserType::Staff,
        'status' => UserStatus::Active,
    ], $attributes));
    $user->assignRole('Support Agent');

    return $user;
}

// ── Creating a staff account ────────────────────────────────────────────────

it('emails a new staff member a link to set their password, not a code', function () {
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $admin->assignRole('Super Administrator');

    $this->actingAs($admin)
        ->post(adminUrl('/staff'), [
            'name' => 'Amina Bello',
            'email' => 'amina@firstmaket.test',
            'role' => 'Support Agent',
        ])
        ->assertRedirect();

    $created = User::query()->where('email', 'amina@firstmaket.test')->firstOrFail();

    Notification::assertSentTo($created, StaffPasswordResetNotification::class);
});

it('lets an administrator send the link again', function () {
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $admin->assignRole('Super Administrator');

    $staff = staffMember();

    $this->actingAs($admin)
        ->post(adminUrl("/staff/{$staff->uuid}/password-link"))
        ->assertRedirect();

    Notification::assertSentTo($staff, StaffPasswordResetNotification::class);
});

// ── Asking for a reset ──────────────────────────────────────────────────────

it('emails a staff member a link when they ask for one', function () {
    $staff = staffMember(['email' => 'agent@firstmaket.test']);

    $this->post(adminUrl('/forgot-password'), ['email' => 'agent@firstmaket.test'])
        ->assertRedirect();

    Notification::assertSentTo($staff, StaffPasswordResetNotification::class);
});

it('says the same thing for an address that has no staff account', function () {
    // Otherwise the form becomes a way to work out which addresses are
    // FirstMaket staff — the exact list a phishing run wants.
    $known = staffMember(['email' => 'real@firstmaket.test']);

    $this->post(adminUrl('/forgot-password'), ['email' => 'real@firstmaket.test'])->assertRedirect();
    $realMessage = session('success');

    session()->forget('success');

    $this->post(adminUrl('/forgot-password'), ['email' => 'nobody@nowhere.test'])->assertRedirect();

    expect(session('success'))->toBe($realMessage);
    Notification::assertSentToTimes($known, StaffPasswordResetNotification::class, 1);
});

it('does not email a customer or vendor from the staff form', function () {
    $customer = User::factory()->create(['email' => 'shopper@example.test', 'user_type' => UserType::Customer]);
    $vendor = User::factory()->create(['email' => 'seller@example.test', 'user_type' => UserType::Vendor]);

    $this->post(adminUrl('/forgot-password'), ['email' => 'shopper@example.test'])->assertRedirect();
    $this->post(adminUrl('/forgot-password'), ['email' => 'seller@example.test'])->assertRedirect();

    Notification::assertNothingSentTo($customer);
    Notification::assertNothingSentTo($vendor);
});

it('will not let a suspended staff account reset its way back in', function () {
    $suspended = staffMember(['email' => 'gone@firstmaket.test', 'status' => UserStatus::Suspended]);

    $this->post(adminUrl('/forgot-password'), ['email' => 'gone@firstmaket.test'])->assertRedirect();

    Notification::assertNothingSentTo($suspended);
});

// ── Using the link ──────────────────────────────────────────────────────────

it('sets a new password from a valid link', function () {
    $staff = staffMember(['email' => 'agent@firstmaket.test']);
    $token = Password::broker()->createToken($staff);

    $this->post(adminUrl('/reset-password'), [
        'token' => $token,
        'email' => 'agent@firstmaket.test',
        'password' => 'Correct-Horse-9!',
        'password_confirmation' => 'Correct-Horse-9!',
    ])->assertRedirect(route('admin.login'));

    expect(Hash::check('Correct-Horse-9!', $staff->fresh()->password))->toBeTrue();
});

it('refuses a token issued for a different account type', function () {
    // A shared address between a vendor and a staff account must not let a
    // vendor token open the staff account.
    $vendor = User::factory()->create(['email' => 'shared@example.test', 'user_type' => UserType::Vendor]);
    $token = Password::broker()->createToken($vendor);

    $this->post(adminUrl('/reset-password'), [
        'token' => $token,
        'email' => 'shared@example.test',
        'password' => 'Correct-Horse-9!',
        'password_confirmation' => 'Correct-Horse-9!',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('Correct-Horse-9!', $vendor->fresh()->password))->toBeFalse();
});

it('refuses a token that has already been used', function () {
    $staff = staffMember(['email' => 'agent@firstmaket.test']);
    $token = Password::broker()->createToken($staff);

    $payload = [
        'token' => $token,
        'email' => 'agent@firstmaket.test',
        'password' => 'Correct-Horse-9!',
        'password_confirmation' => 'Correct-Horse-9!',
    ];

    $this->post(adminUrl('/reset-password'), $payload)->assertRedirect(route('admin.login'));
    $this->post(adminUrl('/reset-password'), $payload)->assertSessionHasErrors('email');
});

it('refuses a tampered token', function () {
    staffMember(['email' => 'agent@firstmaket.test']);

    $this->post(adminUrl('/reset-password'), [
        'token' => 'not-a-real-token',
        'email' => 'agent@firstmaket.test',
        'password' => 'Correct-Horse-9!',
        'password_confirmation' => 'Correct-Horse-9!',
    ])->assertSessionHasErrors('email');
});

it('refuses to reset a suspended account even with a valid token', function () {
    $staff = staffMember(['email' => 'gone@firstmaket.test']);
    $token = Password::broker()->createToken($staff);
    $staff->forceFill(['status' => UserStatus::Suspended])->save();

    $this->post(adminUrl('/reset-password'), [
        'token' => $token,
        'email' => 'gone@firstmaket.test',
        'password' => 'Correct-Horse-9!',
        'password_confirmation' => 'Correct-Horse-9!',
    ])->assertSessionHasErrors('email');
});

it('keeps the reset pages away from somebody already signed in', function () {
    $this->actingAs(staffMember())
        ->get(adminUrl('/forgot-password'))
        ->assertRedirect();
});
