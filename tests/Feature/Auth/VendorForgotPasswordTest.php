<?php

use App\Models\User;
use App\Modules\Vendor\Notifications\VendorPasswordResetNotification;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * A vendor asking for their own password reset.
 *
 * The reset itself already existed, but only staff could trigger it — a
 * vendor who forgot their password had to telephone support and wait. This
 * is the same email, asked for by the person who needs it.
 *
 * As with staff, the confirmation must not reveal whether the address
 * belongs to an account: a list of verified sellers is worth having to
 * anybody running a scam against them.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function vendorAccount(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'user_type' => UserType::Vendor,
        'status' => UserStatus::Active,
    ], $attributes));
}

it('emails a vendor a link when they ask for one', function () {
    $vendor = vendorAccount(['email' => 'seller@example.test']);

    $this->post(vendorUrl('/forgot-password'), ['email' => 'seller@example.test'])
        ->assertRedirect();

    Notification::assertSentTo($vendor, VendorPasswordResetNotification::class);
});

it('says the same thing for an address that has no vendor account', function () {
    $known = vendorAccount(['email' => 'real-seller@example.test']);

    $this->post(vendorUrl('/forgot-password'), ['email' => 'real-seller@example.test'])->assertRedirect();
    $realMessage = session('success');

    session()->forget('success');

    $this->post(vendorUrl('/forgot-password'), ['email' => 'nobody@nowhere.test'])->assertRedirect();

    expect(session('success'))->toBe($realMessage);
    Notification::assertSentToTimes($known, VendorPasswordResetNotification::class, 1);
});

it('does not email a staff or customer account from the vendor form', function () {
    $staff = User::factory()->create(['email' => 'agent@firstmaket.test', 'user_type' => UserType::Staff]);
    $customer = User::factory()->create(['email' => 'shopper@example.test', 'user_type' => UserType::Customer]);

    $this->post(vendorUrl('/forgot-password'), ['email' => 'agent@firstmaket.test'])->assertRedirect();
    $this->post(vendorUrl('/forgot-password'), ['email' => 'shopper@example.test'])->assertRedirect();

    Notification::assertNothingSentTo($staff);
    Notification::assertNothingSentTo($customer);
});

it('will not let a suspended vendor reset their way back in', function () {
    $suspended = vendorAccount(['email' => 'gone@example.test', 'status' => UserStatus::Suspended]);

    $this->post(vendorUrl('/forgot-password'), ['email' => 'gone@example.test'])->assertRedirect();

    Notification::assertNothingSentTo($suspended);
});

it('shows the forgot-password form to a signed-out visitor', function () {
    $this->get(vendorUrl('/forgot-password'))->assertOk();
});
