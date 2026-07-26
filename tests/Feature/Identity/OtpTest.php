<?php

use App\Models\User;
use App\Modules\Identity\Models\OtpCode;
use App\Modules\Identity\Services\OtpService;
use App\Shared\Contracts\SmsSenderContract;
use App\Shared\Enums\OtpPurpose;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * Captures outgoing SMS so tests can read the plaintext code, which is only
 * ever present in the message (the database stores a hash).
 */
class FakeSmsSender implements SmsSenderContract
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
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSenderContract::class, $this->sms);
});

it('sends a code when an unverified user requests one', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('phone.send'))->assertRedirect();

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['phone'])->toBe($user->phone);
});

it('does not send a code for an already-verified phone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('phone.send'));

    expect($this->sms->sent)->toHaveCount(0);
});

it('verifies the phone with the correct code', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('phone.send'));

    $this->actingAs($user)
        ->post(route('phone.verify'), ['code' => $this->sms->lastCode()])
        ->assertRedirect();

    expect($user->refresh()->hasVerifiedPhone())->toBeTrue();
});

it('rejects a wrong code', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('phone.send'));

    $this->actingAs($user)
        ->post(route('phone.verify'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect($user->refresh()->hasVerifiedPhone())->toBeFalse();
});

it('exposes the plaintext code for local/debug display but never in production', function () {
    $user = User::factory()->unverified()->create();

    config(['app.debug' => true]);
    $this->actingAs($user)->post(route('phone.send'));
    expect(session('devOtpCode'))->toBe($this->sms->lastCode());

    config(['app.debug' => false]);
    $this->actingAs($user)->post(route('phone.send'));
    expect(session('devOtpCode'))->toBeNull();
});

it('rejects an expired code', function () {
    $user = User::factory()->unverified()->create();
    $otp = app(OtpService::class);

    $otp->request($user->phone, OtpPurpose::Registration, $user);
    $code = $this->sms->lastCode();

    $this->travel(OtpService::CODE_TTL_MINUTES + 1)->minutes();

    $this->actingAs($user)
        ->post(route('phone.verify'), ['code' => $code])
        ->assertSessionHasErrors('code');
});

it('burns the code after too many incorrect attempts', function () {
    $user = User::factory()->unverified()->create();

    app(OtpService::class)->request($user->phone, OtpPurpose::Registration, $user);
    $code = $this->sms->lastCode();

    foreach (range(1, OtpService::MAX_ATTEMPTS) as $ignored) {
        $this->actingAs($user)->post(route('phone.verify'), ['code' => '000000']);
    }

    // Even the correct code is now rejected.
    $this->actingAs($user)
        ->post(route('phone.verify'), ['code' => $code])
        ->assertSessionHasErrors('code');
});

it('throttles repeated code requests for the same phone', function () {
    $user = User::factory()->unverified()->create();
    $otp = app(OtpService::class);

    foreach (range(1, OtpService::MAX_REQUESTS_PER_WINDOW) as $ignored) {
        $otp->request($user->phone, OtpPurpose::Registration, $user);
    }

    expect(fn () => $otp->request($user->phone, OtpPurpose::Registration, $user))
        ->toThrow(ValidationException::class);
});

it('invalidates earlier codes when a new one is requested', function () {
    $user = User::factory()->unverified()->create();
    $otp = app(OtpService::class);

    $otp->request($user->phone, OtpPurpose::Registration, $user);
    $firstCode = $this->sms->lastCode();

    $otp->request($user->phone, OtpPurpose::Registration, $user);

    $this->actingAs($user)
        ->post(route('phone.verify'), ['code' => $firstCode])
        ->assertSessionHasErrors('code');
});

it('stores only a hash of the code', function () {
    $user = User::factory()->unverified()->create();

    app(OtpService::class)->request($user->phone, OtpPurpose::Registration, $user);

    $code = $this->sms->lastCode();

    expect(OtpCode::query()->latest('id')->firstOrFail()->code_hash)->not->toContain($code);
});
