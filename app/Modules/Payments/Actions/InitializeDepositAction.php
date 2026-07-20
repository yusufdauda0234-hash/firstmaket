<?php

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\PaymentInitialization;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Support\Str;

/**
 * Starts a wallet deposit: records a Pending Paystack transaction with a
 * fresh reference, then asks the gateway for a hosted checkout URL. The
 * wallet is NOT touched here — only a verified webhook credits it.
 */
class InitializeDepositAction
{
    public function __construct(private readonly PaymentGatewayContract $gateway) {}

    public function execute(User $user, int $amountKobo, string $callbackUrl): PaymentInitialization
    {
        $reference = 'FMW_'.Str::lower((string) Str::ulid());

        $transaction = PaystackTransaction::query()->create([
            'user_id' => $user->id,
            'paystack_reference' => $reference,
            'amount_kobo' => $amountKobo,
            'currency' => 'NGN',
            'status' => PaystackTransactionStatus::Pending,
        ]);

        // Offer every supported funding channel; Paystack lets the customer
        // pick card / bank transfer / USSD on the hosted page.
        $init = $this->gateway->initializeDeposit(
            user: $user,
            amountKobo: $amountKobo,
            reference: $reference,
            channels: ['card', 'bank_transfer', 'ussd'],
            callbackUrl: $callbackUrl,
        );

        $transaction->update(['access_code' => $init->accessCode]);

        return $init;
    }
}
