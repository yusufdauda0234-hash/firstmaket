<?php

use App\Models\User;
use App\Shared\Contracts\SmsSenderContract;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Passwordless OTP login and code-verified password reset
 * (Sprint 2 Addendum).
 */
class AuthFlowFakeSms implements SmsSenderContract
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
    $this->sms = new AuthFlowFakeSms;
    app()->instance(SmsSenderContract::class, $this->sms);
});

it('signs a customer in with a one-time SMS code instead of a password', function () {
    $user = User::factory()->create(['phone' => '+2348011122233']);
    $user->assignRole('Customer');

    $this->postJson('/auth/code/send', ['identifier' => '+2348011122233', 'purpose' => 'login'])
        ->assertOk();

    $this->post('/auth/code/login', [
        'identifier' => '+2348011122233',
        'code' => $this->sms->lastCode(),
    ])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

it('rejects a code login with the wrong code', function () {
    $user = User::factory()->create(['phone' => '+2348011122233']);
    $user->assignRole('Customer');

    $this->postJson('/auth/code/send', ['identifier' => '+2348011122233', 'purpose' => 'login']);

    $wrong = $this->sms->lastCode() === '000000' ? '111111' : '000000';

    $this->post('/auth/code/login', [
        'identifier' => '+2348011122233',
        'code' => $wrong,
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('refuses to send a login code to an unknown identifier', function () {
    $this->postJson('/auth/code/send', ['identifier' => '+2348099999999', 'purpose' => 'login'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('identifier');
});

it('resets the password with a code and signs the customer in', function () {
    $user = User::factory()->create(['phone' => '+2348011122233']);
    $user->assignRole('Customer');

    $this->postJson('/auth/code/send', ['identifier' => '+2348011122233', 'purpose' => 'password_reset'])
        ->assertOk();

    $this->post('/auth/password/reset', [
        'identifier' => '+2348011122233',
        'code' => $this->sms->lastCode(),
        'password' => 'new-secret-pass1',
        'password_confirmation' => 'new-secret-pass1',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('new-secret-pass1', $user->refresh()->password))->toBeTrue();
});

it('marks the phone verified after a successful code login through that phone', function () {
    $user = User::factory()->unverified()->create(['phone' => '+2348011122233']);
    $user->assignRole('Customer');

    $this->postJson('/auth/code/send', ['identifier' => '+2348011122233', 'purpose' => 'login']);

    $this->post('/auth/code/login', [
        'identifier' => '+2348011122233',
        'code' => $this->sms->lastCode(),
    ]);

    expect($user->refresh()->phone_verified_at)->not->toBeNull();
});

it('never reveals staff accounts through the public identify endpoint', function () {
    $staff = User::factory()->staff()->create(['email' => 'admin@firstmarket.ng']);

    $this->postJson('/auth/identify', ['identifier' => 'admin@firstmarket.ng'])
        ->assertOk()
        ->assertJson(['exists' => false]);
});
