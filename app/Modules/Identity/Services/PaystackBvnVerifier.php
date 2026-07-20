<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Shared\Contracts\BvnVerifierContract;
use App\Shared\Contracts\IdentityCheckResult;
use Illuminate\Support\Facades\Http;

/**
 * Paystack Identity Verification (Customer Validation) driver
 * (https://paystack.com/docs/identity-verification/validate-customer/).
 * Paystack validates asynchronously in production via the
 * customeridentification webhook; for MVP we treat a synchronous 200 as
 * "check accepted and passed" and revisit when the Payments module lands the
 * shared webhook plumbing in Sprint 4.
 */
class PaystackBvnVerifier implements BvnVerifierContract
{
    public function verify(User $user, string $bvn): IdentityCheckResult
    {
        [$firstName, $lastName] = $this->splitName($user->name);

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->asJson()
            ->post(config('services.paystack.base_url').'/customer/'.urlencode($user->email).'/identification', [
                'country' => 'NG',
                'type' => 'bvn',
                'value' => $bvn,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);

        if ($response->successful() && $response->json('status') === true) {
            return new IdentityCheckResult(
                passed: true,
                provider: 'paystack',
                providerReference: $response->json('data.reference'),
            );
        }

        return new IdentityCheckResult(
            passed: false,
            provider: 'paystack',
            failureReason: $response->json('message') ?? 'BVN verification failed.',
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
