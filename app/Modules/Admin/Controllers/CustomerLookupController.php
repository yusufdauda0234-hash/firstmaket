<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\Savings;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Support\Models\SupportTicket;
use App\Shared\Enums\UserType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Support-agent read-only customer lookup
 * (docs/FirstMaket_Implementation_Plan.md Sprint 7). Deliberately narrow:
 * order/goal/savings CONTEXT only — never card data (none is stored), and no
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
                ? $this->customerContext(
                    (int) $request->query('customer'),
                    // What somebody has saved is more sensitive than the
                    // ticket they opened, so it is held behind its own
                    // permission rather than riding along with the rest of
                    // the support context.
                    showFinancials: $request->user()->can('savings.view'),
                )
                : null,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function customerContext(int $customerId, bool $showFinancials): ?array
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
            'canSeeFinancials' => $showFinancials,
            'savingsBalanceKobo' => $showFinancials
                ? (int) Savings::query()->where('user_id', $user->id)->value('balance_kobo')
                : null,
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
            // Held behind plans.view for the same reason as the balance: an
            // agent working a delivery complaint has no need to know what
            // else somebody is saving towards.
            'savingsGoals' => $showFinancials
                ? SavingsGoal::query()
                    ->where('user_id', $user->id)
                    ->with('items.product:id,name')
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                    ->map(fn (SavingsGoal $goal) => [
                        'uuid' => $goal->uuid,
                        'productNames' => $goal->items->map(fn ($item) => $item->product->name)->implode(', '),
                        'status' => $goal->status->value,
                        'targetKobo' => $goal->target_kobo,
                    ])
                : collect(),
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
