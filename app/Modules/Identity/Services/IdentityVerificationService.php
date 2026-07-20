<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\IdentityVerification;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Contracts\BvnVerifierContract;
use App\Shared\Contracts\IdentityCheckResult;
use App\Shared\Contracts\NinVerifierContract;
use App\Shared\Enums\IdentityStatus;
use App\Shared\Enums\IdentityVerificationStatus;
use App\Shared\Enums\IdentityVerificationType;
use Illuminate\Support\Facades\DB;

/**
 * Runs a BVN/NIN check, records the attempt in identity_verifications, and
 * rolls the aggregate identity_status up onto the customer or vendor profile.
 * The raw BVN/NIN is stored only in the profile's encrypted column, never in
 * the verification row or logs.
 */
class IdentityVerificationService
{
    public function __construct(
        private readonly BvnVerifierContract $bvnVerifier,
        private readonly NinVerifierContract $ninVerifier,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    public function verifyBvn(User $user, string $bvn): IdentityVerification
    {
        return $this->run($user, IdentityVerificationType::Bvn, $bvn, fn () => $this->bvnVerifier->verify($user, $bvn));
    }

    public function verifyNin(User $user, string $nin): IdentityVerification
    {
        return $this->run($user, IdentityVerificationType::Nin, $nin, fn () => $this->ninVerifier->verify($user, $nin));
    }

    /**
     * @param  callable(): IdentityCheckResult  $check
     */
    private function run(User $user, IdentityVerificationType $type, string $idNumber, callable $check): IdentityVerification
    {
        $result = $check();

        return DB::transaction(function () use ($user, $type, $idNumber, $result) {
            $verification = IdentityVerification::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'provider' => $result->provider,
                'provider_reference' => $result->providerReference,
                'status' => $result->passed ? IdentityVerificationStatus::Passed : IdentityVerificationStatus::Failed,
                'verified_at' => $result->passed ? now() : null,
                'failure_reason' => $result->failureReason,
                'metadata' => $result->metadata,
            ]);

            $this->updateProfile($user, $type, $idNumber, $result->passed);

            $this->auditLogger->log(
                actor: $user,
                subject: $verification,
                action: $result->passed ? 'identity.verification_passed' : 'identity.verification_failed',
                newValues: ['type' => $type->value, 'provider' => $result->provider],
            );

            return $verification;
        });
    }

    private function updateProfile(User $user, IdentityVerificationType $type, string $idNumber, bool $passed): void
    {
        $profile = $user->customerProfile ?? $user->vendorProfile;

        if ($profile === null) {
            return;
        }

        match ($type) {
            IdentityVerificationType::Bvn => $profile->bvn = $idNumber,
            IdentityVerificationType::Nin => $profile->nin = $idNumber,
            IdentityVerificationType::Cac => null,
        };

        if ($profile->getAttribute('identity_status') !== null) {
            // Verified is sticky: one passed check (BVN or NIN) is enough to
            // unlock Product Target Plans, and a later failed check on the
            // other document must not revoke it.
            if ($passed) {
                $profile->identity_status = IdentityStatus::Verified;
            } elseif ($profile->identity_status !== IdentityStatus::Verified) {
                $profile->identity_status = IdentityStatus::Failed;
            }
        }

        $profile->save();
    }
}
