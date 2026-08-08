<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsService;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My Account" landing page (Jumia/AliExpress account-overview pattern):
 * account details, savings balance and verification state at a glance, with
 * links into the dedicated pages for each.
 */
class AccountOverviewController extends Controller
{
    public function show(Request $request, SavingsService $savingsService): Response
    {
        $user = $request->user();
        $savings = $savingsService->getOrCreate($user);

        return Inertia::render('Account/Overview', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'emailVerified' => $user->hasVerifiedEmail(),
                'phone' => $user->phone,
                'phoneVerified' => $user->hasVerifiedPhone(),
                'memberSince' => $user->created_at?->format('F Y'),
            ],
            // Plan credit, not a balance: balance_kobo is a retired wallet
            // column pinned at zero, and this marketplace has no wallet to
            // show. Credit is real money the customer can only spend on
            // another plan.
            'planCreditKobo' => $savings->credit_kobo,
            'activePlanCount' => SavingsGoal::query()
                ->where('user_id', $user->id)
                ->where('status', SavingsGoalStatus::Saving)
                ->count(),
            // AliExpress-style order tracker counts (Sprint 6).
            'orderCounts' => [
                // Goals still being saved towards — the shopper has
                // committed to these but has not bought them yet.
                'saving' => SavingsGoal::query()
                    ->where('user_id', $user->id)
                    ->where('status', SavingsGoalStatus::Saving)
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
