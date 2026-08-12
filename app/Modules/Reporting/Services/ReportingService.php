<?php

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductStatusEvent;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Models\VendorRating;
use App\Shared\Enums\SavingsGoalStatus;
use App\Shared\Enums\UserType;
use App\Shared\Support\TitleCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        // Money in is now plan instalments — there are no balance deposits.
        $deposits = PlanPayment::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'amount_kobo', 'reference', 'created_at']);

        return [
            'count' => $deposits->count(),
            'totalKobo' => (int) $deposits->sum('amount_kobo'),
            'rows' => $deposits->map(fn (PlanPayment $transaction) => [
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
        $goals = SavingsGoal::query()
            ->where('status', SavingsGoalStatus::Fulfilled)
            ->whereBetween('fulfilled_at', [$from, $to])
            ->orderBy('fulfilled_at')
            ->get(['id', 'uuid', 'user_id', 'target_kobo', 'fulfilled_at']);

        return [
            'count' => $goals->count(),
            'rows' => $goals->map(fn (SavingsGoal $goal) => [
                'uuid' => $goal->uuid,
                'userId' => $goal->user_id,
                'targetPriceKobo' => $goal->target_kobo,
                'date' => $goal->fulfilled_at?->toDateString(),
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

    /**
     * What customers are saving towards, as a demand signal.
     *
     * Aggregate only — counts per product, never who wanted it. Phase 2D asks
     * explicitly that forecasting must not expose customer identity, and the
     * cheapest way to guarantee that is for the identity never to be in the
     * result at all rather than filtered out of it later.
     *
     * @return array{count: int, rows: array<int, array<string, mixed>>}
     */
    public function wishlistDemand(int $limit = 50): array
    {
        $rows = DB::table('wishlists')
            ->join('products', 'products.id', '=', 'wishlists.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('products.id', 'products.name', 'products.price_kobo', 'products.stock_quantity', 'categories.name')
            ->select(
                'products.name as product',
                'categories.name as category',
                'products.price_kobo',
                'products.stock_quantity',
                DB::raw('count(*) as saved_by'),
            )
            ->orderByDesc('saved_by')
            ->limit($limit)
            ->get();

        return [
            'count' => $rows->count(),
            'rows' => $rows->map(fn ($row) => [
                // Read through the query builder rather than the model, so
                // the Uppercase cast never runs — names are stored SHOUTING
                // and every other screen shows them title-cased. Formatting
                // here keeps the report and the CSV reading like the rest of
                // the app instead of like a database dump.
                'product' => $row->product === null ? null : TitleCase::format((string) $row->product),
                'category' => $row->category === null ? null : TitleCase::format((string) $row->category),
                'priceKobo' => (int) $row->price_kobo,
                'stockQuantity' => (int) $row->stock_quantity,
                'savedBy' => (int) $row->saved_by,
                // The number that makes this actionable: demand the current
                // shelf cannot serve.
                'shortfall' => max(0, (int) $row->saved_by - (int) $row->stock_quantity),
            ])->all(),
        ];
    }

    /**
     * Plans on course to complete, and roughly when.
     *
     * The projection is deliberately simple: what is left to pay, divided by
     * what this plan pays per instalment, stepped forward at its own cadence.
     * No trend fitting — a plan is a fixed schedule, so anything cleverer
     * would be inventing precision the data does not have.
     *
     * @return array{count: int, totalRemainingKobo: int, rows: array<int, array<string, mixed>>}
     */
    public function expectedCompletions(int $withinDays = 90): array
    {
        $goals = SavingsGoal::query()
            ->where('status', SavingsGoalStatus::Saving)
            ->where('paid_kobo', '>', 0)
            ->with('items.product:id,name')
            ->get();

        $rows = [];
        $totalRemaining = 0;

        foreach ($goals as $goal) {
            $remaining = $goal->remainingKobo();
            $perPayment = max(1, $goal->installment_kobo);
            $paymentsLeft = (int) ceil($remaining / $perPayment);

            $due = $goal->next_due_at?->copy() ?? now();

            if ($goal->cadence !== null) {
                for ($i = 1; $i < $paymentsLeft; $i++) {
                    $due = $goal->cadence->next($due);
                }
            }

            if ($due->diffInDays(now()) > $withinDays && $due->isFuture()) {
                continue;
            }

            $totalRemaining += $remaining;

            $rows[] = [
                // No customer identity: the product and the money, nothing else.
                'product' => $goal->items->first()?->product?->name,
                'targetKobo' => $goal->target_kobo,
                'paidKobo' => $goal->paid_kobo,
                'remainingKobo' => $remaining,
                'paymentsLeft' => $paymentsLeft,
                'progressPercent' => $goal->progressPercent(),
                'expectedAt' => $due->toDateString(),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['expectedAt'], $b['expectedAt']));

        return [
            'count' => count($rows),
            'totalRemainingKobo' => $totalRemaining,
            'rows' => $rows,
        ];
    }

    /**
     * How each vendor is performing, from the Phase 2D ratings.
     *
     * @return array{count: int, rows: array<int, array<string, mixed>>}
     */
    public function vendorPerformance(): array
    {
        $ratings = VendorRating::query()
            ->with(['vendor:id,business_name', 'tier:id,name'])
            ->orderByDesc('score')
            ->get();

        return [
            'count' => $ratings->count(),
            'rows' => $ratings->map(fn (VendorRating $rating) => [
                'vendor' => $rating->vendor?->business_name,
                'tier' => $rating->tier?->name ?? 'Unrated',
                'score' => $rating->score,
                'deliveredOrders' => $rating->delivered_orders,
                'rejectedOrders' => $rating->rejected_orders,
                'returnedOrders' => $rating->returned_orders,
                'latePreparations' => $rating->late_preparations,
                'averageProductRating' => $rating->average_product_rating,
            ])->all(),
        ];
    }
}
