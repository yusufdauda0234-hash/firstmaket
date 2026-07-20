<?php

use App\Models\User;
use App\Modules\Customer\Models\CustomerProfile;
use App\Shared\Contracts\BvnVerifierContract;
use App\Shared\Contracts\IdentityCheckResult;
use App\Shared\Contracts\NinVerifierContract;
use App\Shared\Enums\IdentityStatus;
use App\Shared\Enums\IdentityVerificationStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

function fakeVerifier(bool $passes): object
{
    return new class($passes) implements BvnVerifierContract, NinVerifierContract
    {
        public function __construct(private readonly bool $passes) {}

        public function verify(User $user, string $idNumber): IdentityCheckResult
        {
            return $this->passes
                ? new IdentityCheckResult(passed: true, provider: 'fake', providerReference: 'ref-123')
                : new IdentityCheckResult(passed: false, provider: 'fake', failureReason: 'No match found.');
        }
    };
}

function customerWithProfile(): User
{
    $user = User::factory()->create();
    $user->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $user->id]);

    return $user;
}

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('blocks Product Target Plan activation until BVN/NIN verification passes', function () {
    $user = customerWithProfile();

    expect($user->customerProfile->canActivateTargetPlans())->toBeFalse();
});

it('marks the profile verified after a passing BVN check', function () {
    app()->instance(BvnVerifierContract::class, fakeVerifier(true));

    $user = customerWithProfile();

    $this->actingAs($user)
        ->post(route('identity.bvn'), ['bvn' => '12345678901'])
        ->assertRedirect(route('identity.status'));

    $profile = $user->customerProfile->refresh();

    expect($profile->identity_status)->toBe(IdentityStatus::Verified)
        ->and($profile->canActivateTargetPlans())->toBeTrue()
        ->and($profile->bvn)->toBe('12345678901');

    $this->assertDatabaseHas('identity_verifications', [
        'user_id' => $user->id,
        'type' => 'bvn',
        'status' => IdentityVerificationStatus::Passed->value,
    ]);
});

it('records a failed NIN check without unlocking target plans', function () {
    app()->instance(NinVerifierContract::class, fakeVerifier(false));

    $user = customerWithProfile();

    $this->actingAs($user)->post(route('identity.nin'), ['nin' => '12345678901']);

    $profile = $user->customerProfile->refresh();

    expect($profile->identity_status)->toBe(IdentityStatus::Failed)
        ->and($profile->canActivateTargetPlans())->toBeFalse();

    $this->assertDatabaseHas('identity_verifications', [
        'user_id' => $user->id,
        'type' => 'nin',
        'status' => IdentityVerificationStatus::Failed->value,
        'failure_reason' => 'No match found.',
    ]);
});

it('keeps the profile verified when a later check on the other document fails', function () {
    app()->instance(BvnVerifierContract::class, fakeVerifier(true));
    app()->instance(NinVerifierContract::class, fakeVerifier(false));

    $user = customerWithProfile();

    $this->actingAs($user)->post(route('identity.bvn'), ['bvn' => '12345678901']);
    $this->actingAs($user)->post(route('identity.nin'), ['nin' => '10987654321']);

    expect($user->customerProfile->refresh()->identity_status)->toBe(IdentityStatus::Verified);
});

it('audits every verification attempt', function () {
    app()->instance(BvnVerifierContract::class, fakeVerifier(true));

    $user = customerWithProfile();

    $this->actingAs($user)->post(route('identity.bvn'), ['bvn' => '12345678901']);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $user->id,
        'action' => 'identity.verification_passed',
    ]);
});

it('rejects a malformed BVN before calling any provider', function () {
    $user = customerWithProfile();

    $this->actingAs($user)
        ->post(route('identity.bvn'), ['bvn' => '123'])
        ->assertSessionHasErrors('bvn');

    $this->assertDatabaseCount('identity_verifications', 0);
});
