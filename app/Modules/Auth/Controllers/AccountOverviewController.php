<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\PlanStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My Account" landing page (Jumia/AliExpress account-overview pattern):
 * account details, wallet balance and verification state at a glance, with
 * links into the dedicated pages for each.
 */
class AccountOverviewController extends Controller
{
    public function show(Request $request, WalletService $walletService): Response
    {
        $user = $request->user();
        $wallet = $walletService->getOrCreate($user);

        return Inertia::render('Account/Overview', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'emailVerified' => $user->hasVerifiedEmail(),
                'phone' => $user->phone,
                'phoneVerified' => $user->hasVerifiedPhone(),
                'memberSince' => $user->created_at?->format('F Y'),
            ],
            'walletBalanceKobo' => $wallet->balance_kobo,
            // AliExpress-style order tracker counts (Sprint 6).
            'orderCounts' => [
                // whereNotNull matters here: plan_id is null on Sprint 8 cart
                // checkout orders, and a NULL inside a NOT IN subquery would
                // make the whole condition match nothing.
                'awaitingAddress' => ProductTargetPlan::query()
                    ->where('user_id', $user->id)
                    ->where('status', PlanStatus::ReadyForDelivery)
                    ->whereNotIn('id', Order::query()->whereNotNull('plan_id')->select('plan_id'))
                    ->count(),
                'processing' => Order::query()
                    ->where('customer_id', $user->id)
                    ->whereIn('status', ['pending', 'processing', 'ready_for_pickup', 'packed'])
                    ->count(),
                'shipped' => Order::query()
                    ->where('customer_id', $user->id)
                    ->whereIn('status', ['shipped', 'out_for_delivery'])
                    ->count(),
                'toConfirm' => Order::query()
                    ->where('customer_id', $user->id)
                    ->where('status', 'delivered')
                    ->whereNull('delivery_confirmed_at')
                    ->count(),
            ],
        ]);
    }
}
