<?php

use App\Models\User;
use App\Modules\Identity\Notifications\OtpCodeNotification;
use App\Shared\Contracts\SmsSenderContract;
use App\Shared\Enums\IdentityStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * OTP-first registration (Sprint 2 Addendum): identifier → code through the
 * matching channel → name + password. See also AuthFlowTest for the
 * sign-in-side flows.
 */
class RegistrationFakeSms implements SmsSenderContract
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
    $this->sms = new RegistrationFakeSms;
    app()->instance(SmsSenderContract::class, $this->sms);
});

function lastEmailOtpCode(): string
{
    $code = '';

    Notification::assertSentOnDemand(
        OtpCodeNotification::class,
        function (OtpCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        }
    );

    return $code;
}

it('registers a new customer with an email identifier and an email OTP', function () {
    Notification::fake();

    $this->postJson('/auth/identify', ['identifier' => 'Ada@Example.com'])
        ->assertOk()
        ->assertJson(['exists' => false, 'channel' => 'email', 'identifier' => 'ada@example.com']);

    $this->postJson('/auth/code/send', ['identifier' => 'ada@example.com', 'purpose' => 'registration'])
        ->assertOk();

    $this->postJson('/auth/code/verify', ['identifier' => 'ada@example.com', 'code' => lastEmailOtpCode()])
        ->assertOk()
        ->assertJson(['verified' => true]);

    $response = $this->post('/register', [
        'name' => 'Ada Lovelace',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect($user->hasRole('Customer'))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone)->toBeNull();

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard'));
});

it('registers a new customer with a phone identifier and an SMS OTP', function () {
    $this->postJson('/auth/identify', ['identifier' => '08012345678'])
        ->assertOk()
        ->assertJson(['exists' => false, 'channel' => 'sms', 'identifier' => '+2348012345678']);

    $this->postJson('/auth/code/send', ['identifier' => '+2348012345678', 'purpose' => 'registration'])
        ->assertOk();

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['phone'])->toBe('+2348012345678');

    $this->postJson('/auth/code/verify', ['identifier' => '+2348012345678', 'code' => $this->sms->lastCode()])
        ->assertOk();

    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $user = User::query()->where('phone', '+2348012345678')->firstOrFail();

    expect($user->phone_verified_at)->not->toBeNull()
        ->and($user->email)->toBeNull()
        ->and($user->hasRole('Customer'))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

it('creates a customer profile with an unverified identity status', function () {
    Notification::fake();

    $this->postJson('/auth/code/send', ['identifier' => 'ada@example.com', 'purpose' => 'registration']);
    $this->postJson('/auth/code/verify', ['identifier' => 'ada@example.com', 'code' => lastEmailOtpCode()]);
    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect($user->customerProfile)->not->toBeNull()
        ->and($user->customerProfile->identity_status)->toBe(IdentityStatus::Unverified)
        ->and($user->customerProfile->canActivateTargetPlans())->toBeFalse();
});

it('rejects registration without a code-verified identifier', function () {
    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertSessionHasErrors('identifier');

    $this->assertGuest();
});

it('refuses to send a registration code to an identifier that already has an account', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/auth/code/send', ['identifier' => 'taken@example.com', 'purpose' => 'registration'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('identifier');
});

it('reports an existing account through identify so the modal shows the password step', function () {
    User::factory()->create(['email' => 'known@example.com']);

    $this->postJson('/auth/identify', ['identifier' => 'known@example.com'])
        ->assertOk()
        ->assertJson(['exists' => true, 'channel' => 'email']);
});
