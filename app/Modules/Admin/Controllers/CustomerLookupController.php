<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Wallet\Models\Wallet;
use App\Shared\Enums\UserType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Support-agent read-only customer lookup
 * (docs/FirstMaket_Implementation_Plan.md Sprint 7). Deliberately narrow:
 * order/plan/wallet CONTEXT only — never card data (none is stored), and no
 * mutation endpoints exist here at all.
 */
class CustomerLookupController extends Controller
{
    public function index(Request $request): Response
    {
        $term = trim((string) $request->query('q'));

        $results = $term === '' ? collect() : User::query()
            ->where('user_type', UserType::Customer)
            ->where(function ($query) use ($term) {
                $query->where('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone', 'created_at'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'joined' => $user->created_at->format('M Y'),
            ]);

        return Inertia::render('Admin/Support/Lookup', [
            'query' => $term,
            'results' => $results,
            'customer' => $request->query('customer')
                ? $this->customerContext((int) $request->query('customer'))
                : null,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function customerContext(int $customerId): ?array
    {
        $user = User::query()
            ->where('user_type', UserType::Customer)
            ->find($customerId);

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'emailVerified' => $user->hasVerifiedEmail(),
            'phoneVerified' => $user->hasVerifiedPhone(),
            'memberSince' => $user->created_at->format('j M Y'),
            'walletBalanceKobo' => (int) Wallet::query()->where('user_id', $user->id)->value('balance_kobo'),
            'orders' => Order::query()
                ->where('customer_id', $user->id)
                ->with('product:id,name')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (Order $order) => [
                    'uuid' => $order->uuid,
                    'productName' => $order->product->name,
                    'status' => $order->status->value,
                    'statusLabel' => $order->status->label(),
                    'lockedPriceKobo' => $order->locked_price_kobo,
                    'createdAt' => $order->created_at->format('j M Y'),
                ]),
            'plans' => ProductTargetPlan::query()
                ->where('user_id', $user->id)
                ->with('product:id,name')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (ProductTargetPlan $plan) => [
                    'uuid' => $plan->uuid,
                    'productName' => $plan->product->name,
                    'status' => $plan->status->value,
                    'progress' => (float) $plan->progress_percentage,
                    'targetPriceKobo' => $plan->target_price_kobo,
                ]),
            'tickets' => SupportTicket::query()
                ->where('customer_id', $user->id)
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (SupportTicket $ticket) => [
                    'uuid' => $ticket->uuid,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status->value,
                ]),
        ];
    }
}
