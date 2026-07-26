<?php

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductStatusEvent;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Shared\Enums\PlanStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\WalletTransactionType;
use Illuminate\Support\Carbon;

/**
 * Sprint 9 operational reports (docs/FirstMaket_Implementation_Plan.md):
 * every report reads directly from its source table for the given date
 * range — no snapshot/cache table — so "reports match source tables" holds
 * by construction rather than by a separate reconciliation step.
 */
class ReportingService
{
    /** @return array{total: int, customers: int, vendors: int, rows: array<int, array<string, mixed>>} */
    public function signups(Carbon $from, Carbon $to): array
    {
        $users = User::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['id', 'name', 'email', 'user_type', 'created_at']);

        return [
            'total' => $users->count(),
            'customers' => $users->where('user_type', UserType::Customer)->count(),
            'vendors' => $users->where('user_type', UserType::Vendor)->count(),
            'rows' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'type' => $user->user_type->value,
                'date' => $user->created_at->toDateString(),
            ])->all(),
        ];
    }

    /** @return array{count: int, totalKobo: int, rows: array<int, array<string, mixed>>} */
    public function deposits(Carbon $from, Carbon $to): array
    {
        $deposits = WalletTransaction::query()
            ->where('type', WalletTransactionType::Deposit)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'amount_kobo', 'reference', 'created_at']);

        return [
            'count' => $deposits->count(),
            'totalKobo' => (int) $deposits->sum('amount_kobo'),
            'rows' => $deposits->map(fn (WalletTransaction $transaction) => [
                'id' => $transaction->id,
                'userId' => $transaction->user_id,
                'amountKobo' => $transaction->amount_kobo,
                'reference' => $transaction->reference,
                'date' => $transaction->created_at->toDateString(),
            ])->all(),
        ];
    }

    /** @return array{count: int, rows: array<int, array<string, mixed>>} */
    public function planCompletions(Carbon $from, Carbon $to): array
    {
        $plans = ProductTargetPlan::query()
            ->where('status', PlanStatus::Completed)
            ->whereBetween('completed_at', [$from, $to])
            ->orderBy('completed_at')
            ->get(['id', 'uuid', 'user_id', 'target_price_kobo', 'completed_at']);

        return [
            'count' => $plans->count(),
            'rows' => $plans->map(fn (ProductTargetPlan $plan) => [
                'uuid' => $plan->uuid,
                'userId' => $plan->user_id,
                'targetPriceKobo' => $plan->target_price_kobo,
                'date' => $plan->completed_at?->toDateString(),
            ])->all(),
        ];
    }

    /** @return array{count: int, totalKobo: int, byStatus: array<string, int>, rows: array<int, array<string, mixed>>} */
    public function orderVolume(Carbon $from, Carbon $to): array
    {
        $orders = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['id', 'uuid', 'vendor_id', 'status', 'locked_price_kobo', 'created_at']);

        return [
            'count' => $orders->count(),
            'totalKobo' => (int) $orders->sum('locked_price_kobo'),
            'byStatus' => $orders->countBy(fn (Order $order) => $order->status->value)->all(),
            'rows' => $orders->map(fn (Order $order) => [
                'uuid' => $order->uuid,
                'vendorId' => $order->vendor_id,
                'status' => $order->status->value,
                'lockedPriceKobo' => $order->locked_price_kobo,
                'date' => $order->created_at->toDateString(),
            ])->all(),
        ];
    }

    /** @return array{newVendors: int, approvedProducts: int, rows: array<int, array<string, mixed>>} */
    public function vendorActivity(Carbon $from, Carbon $to): array
    {
        $vendors = VendorProfile::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['id', 'uuid', 'business_name', 'status', 'created_at']);

        $approvedProducts = ProductStatusEvent::query()
            ->where('new_status', 'approved')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        return [
            'newVendors' => $vendors->count(),
            'approvedProducts' => $approvedProducts,
            'rows' => $vendors->map(fn (VendorProfile $vendor) => [
                'uuid' => $vendor->uuid,
                'businessName' => $vendor->business_name,
                'status' => $vendor->status->value,
                'date' => $vendor->created_at->toDateString(),
            ])->all(),
        ];
    }

    /** @return array{approved: int, rejected: int, rows: array<int, array<string, mixed>>} */
    public function productApprovalOutcomes(Carbon $from, Carbon $to): array
    {
        $events = ProductStatusEvent::query()
            ->whereIn('new_status', ['approved', 'rejected'])
            ->whereBetween('created_at', [$from, $to])
            ->with('product:id,name')
            ->orderBy('created_at')
            ->get();

        return [
            'approved' => $events->where('new_status', 'approved')->count(),
            'rejected' => $events->where('new_status', 'rejected')->count(),
            'rows' => $events->map(fn (ProductStatusEvent $event) => [
                'productId' => $event->product_id,
                'productName' => $event->product->name,
                'outcome' => $event->new_status,
                'note' => $event->note,
                'date' => $event->created_at->toDateString(),
            ])->all(),
        ];
    }
}
