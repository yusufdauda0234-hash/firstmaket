<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Services\UserModerationService;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Wallet\Models\Wallet;
use App\Shared\Enums\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 9 user management (docs/FirstMaket_Implementation_Plan.md):
 * suspend/ban/reactivate customer accounts. Deliberately customer-only —
 * UserModerationService itself also refuses staff accounts as a second,
 * server-side guard. This is a mutation-capable sibling of the read-only
 * Support Agent lookup (CustomerLookupController); the two are kept
 * separate because they're gated by different permissions
 * (customers.suspend vs support.manage).
 */
class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $term = trim((string) $request->query('q', ''));
        $statusFilter = (string) $request->query('status', '');

        $users = User::query()
            ->where('user_type', UserType::Customer)
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            }))
            ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status->value,
                'joinedAt' => $user->created_at->format('j M Y'),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'query' => $term,
            'status' => $statusFilter,
        ]);
    }

    public function show(User $user): Response
    {
        abort_unless($user->user_type === UserType::Customer, 404);

        $user->loadMissing('statusChangedBy:id,name');

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status->value,
                'statusReason' => $user->status_reason,
                'statusChangedBy' => $user->statusChangedBy?->name,
                'statusChangedAt' => $user->status_changed_at?->format('j M Y, g:ia'),
                'joinedAt' => $user->created_at->format('j M Y'),
                'walletBalanceKobo' => (int) (Wallet::query()->where('user_id', $user->id)->value('balance_kobo') ?? 0),
                'orderCount' => Order::query()->where('customer_id', $user->id)->count(),
                'planCount' => ProductTargetPlan::query()->where('user_id', $user->id)->count(),
            ],
        ]);
    }

    public function suspend(Request $request, User $user, UserModerationService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $service->suspend($user, $request->user(), $validated['reason']);

        return redirect()->route('admin.users.show', $user)->with('success', 'Account suspended — sessions end on their next request.');
    }

    public function ban(Request $request, User $user, UserModerationService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $service->ban($user, $request->user(), $validated['reason']);

        return redirect()->route('admin.users.show', $user)->with('success', 'Account banned — sessions end on their next request.');
    }

    public function reactivate(Request $request, User $user, UserModerationService $service): RedirectResponse
    {
        $service->reactivate($user, $request->user());

        return redirect()->route('admin.users.show', $user)->with('success', 'Account reactivated.');
    }
}
