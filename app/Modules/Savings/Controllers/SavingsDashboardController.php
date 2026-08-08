<?php

namespace App\Modules\Savings\Controllers;

use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsService;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's Pay Small Small plans.
 *
 * There is no balance to show — money lives inside the plan it was paid
 * into. The only figure that sits outside a plan is credit carried over
 * from one the customer cancelled, and even that can only be spent on
 * another plan.
 */
class SavingsDashboardController
{
    public function show(Request $request, SavingsService $savings): Response
    {
        $user = $request->user();

        $goals = SavingsGoal::query()
            ->where('user_id', $user->id)
            ->with(['items.product:id,name,slug', 'items.product.images'])
            // Running plans first, then whatever has been settled.
            ->orderByRaw("field(status, 'saving', 'fulfilled', 'cancelled')")
            ->orderByDesc('id')
            ->get()
            ->map(fn (SavingsGoal $goal) => [
                'uuid' => $goal->uuid,
                'status' => $goal->status->value,
                'targetKobo' => $goal->target_kobo,
                'paidKobo' => $goal->paid_kobo,
                'remainingKobo' => $goal->remainingKobo(),
                'progress' => $goal->progressPercent(),
                'canFulfil' => $goal->isSaving() && $goal->isCovered(),
                'cadenceLabel' => $goal->cadence?->label(),
                'installmentKobo' => $goal->installment_kobo,
                'installments' => $goal->installments,
                'installmentsPaid' => $goal->installmentsPaid(),
                'nextDueAt' => $goal->next_due_at?->format('j M Y'),
                'itemCount' => (int) $goal->items->sum('quantity'),
                'title' => $goal->items->count() === 1
                    ? $goal->items->first()->product->name
                    : $goal->items->count().' products',
                'image' => $goal->items->first()?->product->primaryImageUrl(),
                'startedAt' => $goal->started_at?->format('j M Y'),
                'fulfilledAt' => $goal->fulfilled_at?->format('j M Y'),
            ]);

        return Inertia::render('Savings/Index', [
            'goals' => $goals,
            'activeCount' => $goals->where('status', SavingsGoalStatus::Saving->value)->count(),
            'planCreditKobo' => $savings->creditKobo($user),
        ]);
    }
}
