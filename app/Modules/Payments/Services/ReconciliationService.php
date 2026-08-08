<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Payments\Models\SettlementImport;
use App\Modules\Payments\Models\SettlementReconciliationItem;
use App\Modules\Savings\Models\SavingsTransaction;
use App\Shared\Enums\ReconciliationStatus;
use Illuminate\Support\Facades\DB;

/**
 * Finance reconciliation (Sprint 4): compare a batch of Paystack settlement
 * lines against the internal ledger and flag every mismatch. Read-only
 * against the ledger — reconciliation never mutates savings balances.
 */
class ReconciliationService
{
    /**
     * @param  array<int, array{reference: string, amount_kobo: int}>  $providerLines
     */
    public function reconcile(User $actor, array $providerLines, string $provider = 'paystack'): SettlementImport
    {
        return DB::transaction(function () use ($actor, $providerLines, $provider) {
            $import = SettlementImport::query()->create([
                'provider' => $provider,
                'imported_by' => $actor->id,
                'status' => 'pending',
                'started_at' => now(),
            ]);

            $seenReferences = [];

            foreach ($providerLines as $line) {
                $reference = $line['reference'];
                $providerAmount = (int) $line['amount_kobo'];
                $seenReferences[] = $reference;

                $ledger = SavingsTransaction::query()->where('reference', $reference)->first();

                if ($ledger === null) {
                    $status = ReconciliationStatus::MissingInLedger;
                } elseif ($ledger->amount_kobo !== $providerAmount) {
                    $status = ReconciliationStatus::AmountMismatch;
                } else {
                    $status = ReconciliationStatus::Matched;
                }

                SettlementReconciliationItem::query()->create([
                    'settlement_import_id' => $import->id,
                    'paystack_reference' => $reference,
                    'savings_transaction_id' => $ledger?->id,
                    'provider_amount_kobo' => $providerAmount,
                    'ledger_amount_kobo' => $ledger?->amount_kobo,
                    'status' => $status,
                ]);
            }

            // Deposits credited in our ledger that the provider file never
            // reported — these need a human to chase up with Paystack.
            SavingsTransaction::query()
                ->where('type', 'deposit')
                ->when($seenReferences !== [], fn ($q) => $q->whereNotIn('reference', $seenReferences))
                ->get()
                ->each(fn (SavingsTransaction $ledger) => SettlementReconciliationItem::query()->create([
                    'settlement_import_id' => $import->id,
                    'paystack_reference' => $ledger->reference,
                    'savings_transaction_id' => $ledger->id,
                    'provider_amount_kobo' => null,
                    'ledger_amount_kobo' => $ledger->amount_kobo,
                    'status' => ReconciliationStatus::MissingInProvider,
                ]));

            $import->update(['status' => 'completed', 'completed_at' => now()]);

            return $import;
        });
    }
}
