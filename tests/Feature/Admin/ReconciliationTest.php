<?php

use App\Models\User;
use App\Modules\Payments\Services\ReconciliationService;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Shared\Enums\LedgerDirection;
use App\Shared\Enums\ReconciliationStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\WalletTransactionType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 4: Finance reconciliation — matches a Paystack settlement batch
 * against the internal ledger and flags mismatches. Read-only against money.
 */
beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function reconUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_domain').'/reconciliation'.($path === '' ? '' : '/'.ltrim($path, '/'));
}

function financeStaff(): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole('Finance Officer');
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function ledgerDeposit(string $reference, int $amountKobo): WalletTransaction
{
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'balance_kobo' => $amountKobo]);

    return WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'user_id' => $user->id,
        'type' => WalletTransactionType::Deposit,
        'direction' => LedgerDirection::Credit,
        'amount_kobo' => $amountKobo,
        'balance_before_kobo' => 0,
        'balance_after_kobo' => $amountKobo,
        'reference' => $reference,
    ]);
}

it('flags matched, mismatched and missing settlement lines', function () {
    ledgerDeposit('REF_MATCH', 500000);
    ledgerDeposit('REF_MISMATCH', 300000);
    ledgerDeposit('REF_ONLY_LEDGER', 100000); // in ledger, absent from provider file

    $import = app(ReconciliationService::class)->reconcile(financeStaff(), [
        ['reference' => 'REF_MATCH', 'amount_kobo' => 500000],
        ['reference' => 'REF_MISMATCH', 'amount_kobo' => 250000],   // amount differs
        ['reference' => 'REF_ONLY_PROVIDER', 'amount_kobo' => 700000], // not in ledger
    ]);

    $items = $import->items()->get()->keyBy('paystack_reference');

    expect($items['REF_MATCH']->status)->toBe(ReconciliationStatus::Matched)
        ->and($items['REF_MISMATCH']->status)->toBe(ReconciliationStatus::AmountMismatch)
        ->and($items['REF_ONLY_PROVIDER']->status)->toBe(ReconciliationStatus::MissingInLedger)
        ->and($items['REF_ONLY_LEDGER']->status)->toBe(ReconciliationStatus::MissingInProvider);
});

it('lets a Finance Officer import a settlement via the admin portal', function () {
    ledgerDeposit('REF_A', 500000);

    $this->actingAs(financeStaff())
        ->post(reconUrl(), ['settlement' => "reference,amount\nREF_A,5000\nREF_B,2000"])
        ->assertRedirect();

    $this->assertDatabaseHas('settlement_imports', ['status' => 'completed']);
    $this->assertDatabaseHas('settlement_reconciliation_items', [
        'paystack_reference' => 'REF_A',
        'status' => 'matched',
    ]);
    $this->assertDatabaseHas('settlement_reconciliation_items', [
        'paystack_reference' => 'REF_B',
        'status' => 'missing_in_ledger',
    ]);
});

it('denies reconciliation to staff without the permission', function () {
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole('Support Agent');
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($user)->get(reconUrl())->assertForbidden();
});
