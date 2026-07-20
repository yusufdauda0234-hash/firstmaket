<?php

namespace App\Modules\Vendor\Services;

use App\Modules\Vendor\Models\VendorEarning;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\VendorEarningType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single gateway for the vendor earnings ledger
 * (docs/firstmarket-Database_Schema.md section 9) — append-only, fully
 * separate from customer wallets and savings. Earnings credit exactly once
 * per delivered order (unique order_id+type index backs the idempotency);
 * payouts are negative rows written only when a transfer is marked paid.
 */
class EarningsService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    /** Current payable balance = sum of the ledger (last balance_after). */
    public function balanceKobo(VendorProfile $vendor): int
    {
        return (int) VendorEarning::query()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('id')
            ->value('balance_after_kobo');
    }

    /**
     * Credit an order's vendor earning (locked price minus commission).
     * Idempotent: a second call for the same order is a no-op thanks to the
     * unique (order_id, type) index — concurrency-safe by construction.
     */
    public function creditOrderEarning(int $vendorId, int $orderId, int $amountKobo, ?string $note = null): ?VendorEarning
    {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Earning amount must be positive.']);
        }

        try {
            return DB::transaction(function () use ($vendorId, $orderId, $amountKobo, $note) {
                $balanceBefore = $this->lockedBalance($vendorId);

                $earning = VendorEarning::query()->create([
                    'vendor_id' => $vendorId,
                    'order_id' => $orderId,
                    'type' => VendorEarningType::Earning,
                    'amount_kobo' => $amountKobo,
                    'balance_before_kobo' => $balanceBefore,
                    'balance_after_kobo' => $balanceBefore + $amountKobo,
                    'note' => $note ?? 'Order delivered and confirmed',
                    'created_at' => now(),
                ]);

                $this->auditLogger->log(
                    actor: null,
                    subject: $earning,
                    action: 'vendor.earnings_credited',
                    newValues: [
                        'order_id' => $orderId,
                        'amount_kobo' => $amountKobo,
                        'balance_after_kobo' => $earning->balance_after_kobo,
                    ],
                );

                return $earning;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // Already credited for this order — exactly-once holds.
        }
    }

    /** Negative payout row, written only when a transfer is marked paid. */
    public function debitPayout(int $vendorId, int $payoutItemId, int $amountKobo, ?string $note = null): VendorEarning
    {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payout amount must be positive.']);
        }

        return DB::transaction(function () use ($vendorId, $payoutItemId, $amountKobo, $note) {
            $balanceBefore = $this->lockedBalance($vendorId);

            if ($balanceBefore < $amountKobo) {
                throw ValidationException::withMessages(['amount' => 'Payout exceeds the vendor earnings balance.']);
            }

            $earning = VendorEarning::query()->create([
                'vendor_id' => $vendorId,
                'order_id' => null,
                'type' => VendorEarningType::Payout,
                'amount_kobo' => -$amountKobo,
                'balance_before_kobo' => $balanceBefore,
                'balance_after_kobo' => $balanceBefore - $amountKobo,
                'payout_item_id' => $payoutItemId,
                'note' => $note ?? 'Vendor payout',
                'created_at' => now(),
            ]);

            $this->auditLogger->log(
                actor: null,
                subject: $earning,
                action: 'vendor.payout_debited',
                newValues: [
                    'payout_item_id' => $payoutItemId,
                    'amount_kobo' => $amountKobo,
                    'balance_after_kobo' => $earning->balance_after_kobo,
                ],
            );

            return $earning;
        });
    }

    /**
     * Serialize concurrent ledger writes per vendor by locking the vendor's
     * latest ledger row (or the profile row when the ledger is empty).
     */
    private function lockedBalance(int $vendorId): int
    {
        VendorProfile::query()->whereKey($vendorId)->lockForUpdate()->firstOrFail();

        return (int) VendorEarning::query()
            ->where('vendor_id', $vendorId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('balance_after_kobo');
    }
}
