<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('does not send a verification email when registration already proved the email by OTP', function () {
    // OTP-first registration (Sprint 2 Addendum) verifies the email at
    // signup, so the classic VerifyEmail link is only for accounts that add
    // an email later.
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@example.com']);

    expect($user->hasVerifiedEmail())->toBeTrue();

    Notification::assertNotSentTo($user, VerifyEmail::class);
});

it('marks the email verified via the signed link', function () {
    $user = User::factory()->unverified()->create();
    $user->assignRole('Customer');

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'));

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $user->id,
        'action' => 'identity.email_verified',
    ]);
});

it('shows the verification prompt to unverified users', function () {
    $user = User::factory()->unverified()->create();
    $user->assignRole('Customer');

    $this->actingAs($user)->get(route('verification.notice'))->assertOk();
});

it('can resend the verification email', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $user->assignRole('Customer');

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect();

    Notification::assertSentTo($user, VerifyEmail::class);
});
