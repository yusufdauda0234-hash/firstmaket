<?php

use App\Models\User;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Shared\Enums\PaystackTransactionStatus;
use App\Shared\Enums\WalletStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Sprint 4 QA (docs/firstmarket_Implementation_Plan.md): the wallet is only
 * ever credited by a signature-verified, idempotent Paystack webhook — never
 * by the browser — and no withdrawal path exists.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.paystack.secret_key' => 'sk_test_webhook_secret']);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
});

function pendingPaystackTransaction(User $user, string $reference, int $amountKobo): PaystackTransaction
{
    return PaystackTransaction::query()->create([
        'user_id' => $user->id,
        'paystack_reference' => $reference,
        'amount_kobo' => $amountKobo,
        'currency' => 'NGN',
        'status' => PaystackTransactionStatus::Pending,
    ]);
}

/** Post a Paystack webhook with a signature over the exact raw body. */
function postWebhook(array $payload, ?string $signature = null): TestResponse
{
    $json = json_encode($payload) ?: '';
    $signature ??= hash_hmac('sha512', $json, (string) config('services.paystack.secret_key'));

    return test()->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $json);
}

function chargeSuccessPayload(string $reference, int $amountKobo): array
{
    return [
        'event' => 'charge.success',
        'data' => [
            'reference' => $reference,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'channel' => 'card',
            'status' => 'success',
        ],
    ];
}

it('credits the wallet once on a valid charge.success webhook and issues a receipt', function () {
    pendingPaystackTransaction($this->customer, 'FMW_ref_1', 500000);

    postWebhook(chargeSuccessPayload('FMW_ref_1', 500000))->assertOk();

    $wallet = Wallet::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($wallet->balance_kobo)->toBe(500000);

    $this->assertDatabaseHas('wallet_transactions', [
        'user_id' => $this->customer->id,
        'reference' => 'FMW_ref_1',
        'amount_kobo' => 500000,
        'balance_before_kobo' => 0,
        'balance_after_kobo' => 500000,
        'type' => 'deposit',
        'direction' => 'credit',
    ]);
    $this->assertDatabaseHas('receipts', ['user_id' => $this->customer->id, 'amount_kobo' => 500000]);
    $this->assertDatabaseHas('paystack_transactions', [
        'paystack_reference' => 'FMW_ref_1',
        'status' => 'success',
    ]);
});

it('does not double-credit when the same webhook is delivered twice', function () {
    pendingPaystackTransaction($this->customer, 'FMW_ref_dup', 300000);

    postWebhook(chargeSuccessPayload('FMW_ref_dup', 300000))->assertOk();
    postWebhook(chargeSuccessPayload('FMW_ref_dup', 300000))->assertOk();

    expect(Wallet::query()->where('user_id', $this->customer->id)->value('balance_kobo'))->toBe(300000)
        ->and(WalletTransaction::query()->where('reference', 'FMW_ref_dup')->count())->toBe(1);
});

it('rejects a webhook with an invalid signature and credits nothing', function () {
    pendingPaystackTransaction($this->customer, 'FMW_ref_bad', 400000);

    postWebhook(chargeSuccessPayload('FMW_ref_bad', 400000), signature: 'not-a-valid-signature')
        ->assertStatus(400);

    expect(Wallet::query()->where('user_id', $this->customer->id)->value('balance_kobo'))->toBeNull();
    $this->assertDatabaseHas('paystack_webhook_events', ['signature_valid' => false]);
    $this->assertDatabaseMissing('wallet_transactions', ['reference' => 'FMW_ref_bad']);
});

it('ignores a charge.success for an unknown reference', function () {
    postWebhook(chargeSuccessPayload('FMW_unknown', 999999))->assertOk();

    $this->assertDatabaseMissing('wallet_transactions', ['reference' => 'FMW_unknown']);
});

it('never credits the wallet from the browser callback', function () {
    pendingPaystackTransaction($this->customer, 'FMW_ref_cb', 250000);

    $this->actingAs($this->customer)
        ->get(route('payment.callback', ['reference' => 'FMW_ref_cb']))
        ->assertOk();

    // Callback only reports status; the wallet stays untouched until the webhook.
    expect(Wallet::query()->where('user_id', $this->customer->id)->value('balance_kobo'))->toBeNull();
});

it('keeps balance_before/after consistent across sequential deposits', function () {
    pendingPaystackTransaction($this->customer, 'FMW_seq_1', 100000);
    pendingPaystackTransaction($this->customer, 'FMW_seq_2', 250000);

    postWebhook(chargeSuccessPayload('FMW_seq_1', 100000))->assertOk();
    postWebhook(chargeSuccessPayload('FMW_seq_2', 250000))->assertOk();

    $this->assertDatabaseHas('wallet_transactions', [
        'reference' => 'FMW_seq_2',
        'balance_before_kobo' => 100000,
        'balance_after_kobo' => 350000,
    ]);
    expect(Wallet::query()->where('user_id', $this->customer->id)->value('balance_kobo'))->toBe(350000);
});

it('exposes no withdrawal route or endpoint anywhere', function () {
    // No route hints at a withdrawal, and no customer-wallet payout exists.
    // (Sprint 6 vendor payout routes live on the admin subdomain and debit
    // the separate vendor earnings ledger — never customer wallets.)
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toContain('withdraw')
            ->and($route->uri())->not->toContain('wallet/payout')
            ->and((string) $route->getName())->not->toContain('withdraw');

        if (str_contains($route->uri(), 'payout')) {
            expect($route->getDomain())->toBe(config('app.admin_domain'));
        }
    }

    // And guessed withdrawal URLs simply do not exist.
    $this->actingAs($this->customer)->post('/wallet/withdraw', ['amount' => 1000])->assertNotFound();
    $this->actingAs($this->customer)->post('/wallet/withdrawal')->assertNotFound();
});

it('requires an active wallet to be creatable and defaults to zero', function () {
    Wallet::query()->create(['user_id' => $this->customer->id]);
    $wallet = Wallet::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($wallet->balance_kobo)->toBe(0)
        ->and($wallet->status)->toBe(WalletStatus::Active);
});
