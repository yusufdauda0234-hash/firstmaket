<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Shared\Contracts\IdentityCheckResult;
use App\Shared\Contracts\NinVerifierContract;
use Illuminate\Support\Facades\Http;

/**
 * Youverify NIN driver (https://doc.youverify.co). Swappable for Smile
 * Identity or Prembly by binding a different NinVerifierContract
 * implementation from NIN_PROVIDER_DRIVER.
 */
class YouverifyNinVerifier implements NinVerifierContract
{
    public function verify(User $user, string $nin): IdentityCheckResult
    {
        $response = Http::withHeaders(['token' => config('services.nin_provider.key')])
            ->asJson()
            ->post(rtrim((string) config('services.nin_provider.base_url'), '/').'/v2/api/identity/ng/nin', [
                'id' => $nin,
                'isSubjectConsent' => true,
            ]);

        $status = $response->json('data.status');

        if ($response->successful() && $status === 'found') {
            return new IdentityCheckResult(
                passed: true,
                provider: 'youverify',
                providerReference: $response->json('data.id'),
            );
        }

        return new IdentityCheckResult(
            passed: false,
            provider: 'youverify',
            failureReason: $response->json('message') ?? 'NIN verification failed.',
        );
    }
}
