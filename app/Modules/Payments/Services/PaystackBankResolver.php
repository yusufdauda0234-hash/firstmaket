<?php

namespace App\Modules\Payments\Services;

use App\Shared\Contracts\BankAccountResolverContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Paystack implementation of bank account verification for vendor payouts
 * (docs/firstmarket_Implementation_Plan.md Sprint 6): account name
 * resolution and transfer recipient registration. Tests bind an in-memory
 * fake to the contract instead.
 */
class PaystackBankResolver implements BankAccountResolverContract
{
    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl('https://api.paystack.co')
            ->withToken((string) config('services.paystack.secret_key'))
            ->acceptJson();
    }

    public function resolveAccountName(string $accountNumber, string $bankCode): ?string
    {
        $response = $this->baseRequest()->get('/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        if (! $response->successful() || ! $response->json('status')) {
            return null;
        }

        return $response->json('data.account_name');
    }

    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): ?string
    {
        $response = $this->baseRequest()->post('/transferrecipient', [
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => 'NGN',
        ]);

        if (! $response->successful() || ! $response->json('status')) {
            return null;
        }

        return $response->json('data.recipient_code');
    }
}
