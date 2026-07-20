<?php

use App\Models\User;
use App\Modules\Auth\Models\SocialAccount;
use App\Shared\Contracts\SmsSenderContract;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Account settings (Sprint 2 Addendum): add/verify the secondary identifier,
 * set a password on social-only accounts, and unlink social logins without
 * ever locking yourself out.
 */
class SettingsFakeSms implements SmsSenderContract
{
    /** @var list<array{phone: string, message: string}> */
    public array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];
    }

    public function lastCode(): string
    {
        preg_match('/\d{6}/', end($this->sent)['message'], $matches);

        return $matches[0];
    }
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->sms = new SettingsFakeSms;
    app()->instance(SmsSenderContract::class, $this->sms);
});

it('shows the settings page to a signed-in customer', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/account')->assertOk();
});

it('redirects guests to login', function () {
    $this->get('/settings/account')->assertRedirect();
});

it('adds and verifies a phone number through an SMS code', function () {
    $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

    $this->actingAs($user)
        ->post('/settings/identifier/send-code', ['identifier' => '08011122233'])
        ->assertRedirect();

    $this->actingAs($user)
        ->post('/settings/identifier/confirm', [
            'identifier' => '08011122233',
            'code' => $this->sms->lastCode(),
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->phone)->toBe('+2348011122233')
        ->and($user->phone_verified_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $user->id,
        'action' => 'account.identifier_added',
    ]);
});

it('refuses an identifier already used by another account', function () {
    User::factory()->create(['phone' => '+2348011122233']);
    $user = User::factory()->create(['phone' => null]);

    $this->actingAs($user)
        ->post('/settings/identifier/send-code', ['identifier' => '+2348011122233'])
        ->assertSessionHasErrors('identifier');
});

it('refuses to add an email when the account already has one', function () {
    $user = User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($user)
        ->post('/settings/identifier/send-code', ['identifier' => 'second@example.com'])
        ->assertSessionHasErrors('identifier');
});

it('lets a social-only account set a first password without a current one', function () {
    $user = User::factory()->create(['password' => null]);
    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'g-123',
        'provider_email' => $user->email,
    ]);

    $this->actingAs($user)
        ->put('/settings/password', [
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ])
        ->assertRedirect();

    expect(Hash::check('brand-new-secret-1', $user->refresh()->password))->toBeTrue();
});

it('requires the current password when one exists', function () {
    $user = User::factory()->create(['password' => Hash::make('old-secret-123')]);

    $this->actingAs($user)
        ->put('/settings/password', [
            'current_password' => 'wrong-guess',
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('old-secret-123', $user->refresh()->password))->toBeTrue();
});

it('unlinks a social account when a password exists', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-password-1')]);
    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'g-123',
    ]);

    $this->actingAs($user)
        ->delete('/settings/social/google')
        ->assertRedirect();

    expect($user->socialAccounts()->count())->toBe(0);
});

it('refuses to unlink the only way of signing in', function () {
    $user = User::factory()->create(['password' => null]);
    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'g-123',
    ]);

    $this->actingAs($user)
        ->delete('/settings/social/google')
        ->assertSessionHasErrors('provider');

    expect($user->socialAccounts()->count())->toBe(1);
});
