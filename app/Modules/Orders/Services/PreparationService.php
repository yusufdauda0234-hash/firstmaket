<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\VendorPreparationEvent;
use App\Modules\Savings\Models\OpenSaving;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\VendorPreparationStatus;
use App\Shared\Enums\WalletStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vendor-side preparation workflow (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6 step 4): confirm stock, mark Ready for Pickup within the SLA, or
 * reject with a reason. Rejection routes to an admin-managed resolution —
 * refund-to-savings (Open Savings credit) or plan redirection — never cash.
 * Vendors act through their own profile; customer identity is never exposed.
 */
class PreparationService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly WalletService $walletService,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /** Vendor confirms stock is on hand (prep trail only, no status change). */
    public function confirmStock(User $vendorUser, Order $order): void
    {
        $vendor = $this->assertVendorOwnsOrder($vendorUser, $order);

        if ($order->status !== OrderStatus::Processing) {
            throw ValidationException::withMessages(['order' => 'Stock can only be confirmed on a processing order.']);
        }

        VendorPreparationEvent::query()->create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'status' => VendorPreparationStatus::StockConfirmed,
            'created_at' => now(),
        ]);

        $this->auditLogger->log(actor: $vendorUser, subject: $order, action: 'orders.stock_confirmed', newValues: []);
    }

    /** Vendor has packed the item: Processing → Ready for Pickup. */
    public function markReadyForPickup(User $vendorUser, Order $order): Order
    {
        $vendor = $this->assertVendorOwnsOrder($vendorUser, $order);

        $order = $this->orderService->transition($vendorUser, $order, OrderStatus::ReadyForPickup, 'Vendor packed the item');

        VendorPreparationEvent::query()->create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'status' => VendorPreparationStatus::ReadyForPickup,
            'created_at' => now(),
        ]);

        return $order;
    }

    /** Vendor cannot fulfil (e.g. out of stock): Processing → Vendor Rejected. */
    public function reject(User $vendorUser, Order $order, string $reason): Order
    {
        $vendor = $this->assertVendorOwnsOrder($vendorUser, $order);

        $order = $this->orderService->transition($vendorUser, $order, OrderStatus::VendorRejected, $reason);

        VendorPreparationEvent::query()->create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'status' => VendorPreparationStatus::Rejected,
            'note' => $reason,
            'created_at' => now(),
        ]);

        return $order;
    }

    /**
     * Admin resolution for a vendor-rejected order: cancel it and credit the
     * full locked price into the customer's Open Savings pot — money moves
     * back toward another product, never out as cash. The plan stays
     * Completed; the pot credit is the compensating entry.
     */
    public function resolveRejectionToSavings(User $admin, Order $order): Order
    {
        return DB::transaction(function () use ($admin, $order) {
            if ($order->status !== OrderStatus::VendorRejected) {
                throw ValidationException::withMessages(['order' => 'Only a vendor-rejected order can be resolved.']);
            }

            $order = $this->orderService->transition($admin, $order, OrderStatus::Cancelled, 'Refunded to customer Open Savings');

            $pot = OpenSaving::query()
                ->where('user_id', $order->customer_id)
                ->lockForUpdate()
                ->first();

            if ($pot === null) {
                $pot = OpenSaving::query()->create([
                    'user_id' => $order->customer_id,
                    'wallet_id' => $this->walletService->getOrCreate($order->customer)->id,
                    'balance_kobo' => 0,
                    'status' => WalletStatus::Active,
                ]);
            }

            $pot->forceFill(['balance_kobo' => $pot->balance_kobo + $order->locked_price_kobo])->save();

            $this->auditLogger->log(
                actor: $admin,
                subject: $order,
                action: 'orders.rejection_refunded_to_savings',
                newValues: [
                    'amount_kobo' => $order->locked_price_kobo,
                    'open_savings_balance_kobo' => $pot->balance_kobo,
                ],
            );

            return $order;
        });
    }

    /**
     * Scheduler hook: flag Processing orders past their SLA deadline exactly
     * once each, so admin sees overdue preparations. Returns rows flagged.
     */
    public function flagOverduePreparations(): int
    {
        $flagged = 0;

        Order::query()
            ->where('status', OrderStatus::Processing)
            ->where('prepare_due_at', '<', now())
            ->whereDoesntHave('preparationEvents', fn ($q) => $q->where('status', VendorPreparationStatus::SlaBreached))
            ->each(function (Order $order) use (&$flagged) {
                VendorPreparationEvent::query()->create([
                    'order_id' => $order->id,
                    'vendor_id' => $order->vendor_id,
                    'status' => VendorPreparationStatus::SlaBreached,
                    'note' => 'Preparation SLA missed',
                    'created_at' => now(),
                ]);
                $flagged++;
            });

        return $flagged;
    }

    private function assertVendorOwnsOrder(User $vendorUser, Order $order): VendorProfile
    {
        $vendor = VendorProfile::query()->where('user_id', $vendorUser->id)->first();

        if ($vendor === null || $vendor->id !== $order->vendor_id) {
            throw ValidationException::withMessages(['order' => 'This order does not belong to your store.']);
        }

        return $vendor;
    }
}
