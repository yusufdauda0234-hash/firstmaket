<?php

namespace App\Modules\Savings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Payments\Actions\StartPaystackPaymentAction;
use App\Modules\Payments\Models\AutomaticDebit;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\AutomaticDebitService;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A Pay Small Small plan: the locked basket, how much of it is paid off, and
 * the actions that move it along — pay an instalment, take delivery once it
 * is covered, or give up and carry the money to something else.
 */
class SavingsGoalController extends Controller
{
    public function show(Request $request, SavingsGoal $goal): Response
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        // uuid is in the column list because the switch dialog identifies the
        // plan's existing items by it. Leaving it out returned null for every
        // one of them, which React saw as duplicate keys and which made
        // removing one line remove all of them.
        $goal->load(['items.product:id,uuid,name,slug,price_kobo', 'items.product.images', 'payments']);

        return Inertia::render('Savings/GoalShow', [
            'goal' => [
                'uuid' => $goal->uuid,
                'status' => $goal->status->value,
                'targetKobo' => $goal->target_kobo,
                'paidKobo' => $goal->paid_kobo,
                'remainingKobo' => $goal->remainingKobo(),
                'progress' => $goal->progressPercent(),
                'canFulfil' => $goal->isSaving() && $goal->isCovered(),
                'cadenceLabel' => $goal->cadence?->label(),
                'installments' => $goal->installments,
                'installmentsPaid' => $goal->installmentsPaid(),
                'installmentKobo' => $goal->installment_kobo,
                'nextPaymentKobo' => $goal->nextPaymentKobo(),
                'nextDueAt' => $goal->next_due_at?->format('j M Y'),
                'startedAt' => $goal->started_at?->format('j M Y'),
                'fulfilledAt' => $goal->fulfilled_at?->format('j M Y'),
                'deliveryAddress' => $goal->delivery_address,
                'state' => $goal->state,
                'lga' => $goal->lga,
                'recipientName' => $goal->recipient_name,
                'recipientPhone' => $goal->recipient_phone,
                'landmark' => $goal->landmark,
                // What the customer may still change, and how much room is
                // left, so the page can say why an option is unavailable
                // rather than just hiding it.
                'switchesUsed' => $goal->switch_count,
                'switchesAllowed' => SavingsGoalService::maxSwitches(),
                'canSwitch' => $goal->isSaving() && $goal->switch_count < SavingsGoalService::maxSwitches(),
                'canReschedule' => $goal->isSaving() && $goal->remainingKobo() > 0,
                'extensionUsed' => $goal->extension_count > 0,
                'behindOnPayments' => $goal->missedPayments() > 0,
                'isPaused' => $goal->isPaused(),
                'pausedUntil' => $goal->isPaused() ? $goal->pauseExpiresAt()?->format('j M Y') : null,
                'automaticDebit' => $this->automaticDebitPayload($goal),
                // Pausing is refused before the first payment: the price
                // freezes at signup, so a free hold would be a free price lock.
                'canPause' => $goal->isSaving() && ! $goal->isPaused() && $goal->payments_made > 0,
                'durationMonths' => $goal->duration_months,
                'items' => $goal->items->map(fn ($item) => [
                    // Needed so the switch dialog can send back the items the
                    // customer chose to keep.
                    'productUuid' => $item->product->uuid,
                    'productName' => $item->product->name,
                    'productSlug' => $item->product->slug,
                    'productImage' => $item->product->primaryImageUrl(),
                    'quantity' => $item->quantity,
                    // The locked price and today's, so the saver can see the
                    // lock working for them.
                    'lockedUnitPriceKobo' => $item->unit_price_kobo,
                    'currentUnitPriceKobo' => $item->product->price_kobo,
                    'lineTotalKobo' => $item->lineTotalKobo(),
                ]),
                'payments' => $goal->payments->sortByDesc('id')->values()->map(fn (PlanPayment $payment) => [
                    'uuid' => $payment->uuid,
                    'amountKobo' => $payment->amount_kobo,
                    'source' => $payment->source,
                    'at' => $payment->created_at->format('j M Y'),
                ]),
            ],
            // Offered lengths, for changing the schedule or re-choosing one
            // when a switch lands below the current term's minimum.
            'planTerms' => PlanTerm::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('installments')
                ->get()
                ->map(fn (PlanTerm $term) => [
                    'id' => $term->id,
                    'name' => $term->name,
                    'cadenceLabel' => $term->cadence->label(),
                    'installments' => $term->installments,
                    'durationMonths' => $term->duration_months,
                    'durationLabel' => $term->durationLabel(),
                    'minTargetKobo' => $term->min_target_kobo,
                    'paysUpfront' => $term->paysUpfront(),
                ]),
        ]);
    }

    /**
     * Products this plan could be switched to.
     *
     * Its own endpoint rather than the header's catalogue suggest, which
     * returns only a name and a slug: choosing what to switch to is a
     * decision about price and availability, so those have to be in the list.
     */
    /**
     * Every payment made into one plan.
     *
     * Its own page rather than a list on the plan screen: a plan runs for
     * months, so the history outgrows the space, and it is the thing a
     * customer reaches for when reconciling against their bank.
     */
    public function payments(Request $request, SavingsGoal $goal): Response
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $payments = $goal->payments()
            ->orderByDesc('id')
            ->paginate(25)
            ->through(fn (PlanPayment $payment) => [
                'uuid' => $payment->uuid ?? (string) $payment->id,
                'amountKobo' => $payment->amount_kobo,
                'paidAfterKobo' => $payment->paid_after_kobo,
                'source' => $payment->source,
                'reference' => $payment->reference,
                'at' => $payment->created_at?->format('j M Y, g:ia'),
            ]);

        return Inertia::render('Savings/PlanPayments', [
            'goal' => [
                'uuid' => $goal->uuid,
                'title' => $goal->items->first()?->product?->name ?? 'Your plan',
                'targetKobo' => $goal->target_kobo,
                'paidKobo' => $goal->paid_kobo,
                'remainingKobo' => $goal->remainingKobo(),
                'paymentsMade' => $goal->payments_made,
                'installments' => $goal->installments,
            ],
            'payments' => $payments,
        ]);
    }

    public function switchOptions(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query'));
        $categoryId = (int) $request->query('category', 0);

        $products = Product::query()
            ->approved()
            ->where('stock_quantity', '>', 0)
            ->when($query !== '', fn ($builder) => $builder->where('name', 'like', '%'.$query.'%'))
            ->when($categoryId > 0, fn ($builder) => $builder->where('category_id', $categoryId))
            ->with(['images', 'vendor:id,business_name'])
            ->orderByDesc('approved_at')
            // Enough to browse without turning the dialog into a catalogue
            // page of its own.
            ->limit(36)
            ->get()
            ->map(fn (Product $product) => [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'image' => $product->primaryImageUrl(),
                'priceKobo' => $product->price_kobo,
                'stock' => $product->stock_quantity,
                'vendorName' => $product->vendor?->business_name,
            ]);

        return response()->json([
            'products' => $products,
            // Sent every time so the dialog can offer category filters
            // without a second round trip.
            'categories' => Category::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name]),
        ]);
    }

    /** Send the customer to Paystack for the next instalment. */
    public function pay(Request $request, SavingsGoal $goal, StartPaystackPaymentAction $startPayment): SymfonyResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        if (! $goal->isSaving()) {
            throw ValidationException::withMessages(['goal' => 'This plan is already complete.']);
        }

        $validated = $request->validate([
            // Pay the scheduled instalment, or more to finish early — never
            // more than the plan still owes.
            'amount_naira' => ['nullable', 'numeric', 'min:100'],
        ]);

        $requested = isset($validated['amount_naira'])
            ? (int) round(((float) $validated['amount_naira']) * 100)
            : $goal->nextPaymentKobo();

        $amountKobo = min(max($requested, 1), $goal->remainingKobo());

        return $startPayment->forPlanInstallment($request->user(), $goal, $amountKobo);
    }

    /** Take delivery once the plan is fully paid. */
    public function fulfil(Request $request, SavingsGoal $goal, SavingsGoalService $goals): RedirectResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $goals->fulfil($request->user(), $goal);

        return redirect()
            ->route('orders.index')
            ->with('success', 'Plan complete — every vendor has been notified.');
    }

    /**
     * Move the plan onto a different schedule, keeping the item and price.
     *
     * The term is re-read from the database rather than trusted from the
     * request: everything that decides what the customer pays — the cadence,
     * the payment count, the minimum — comes off the row, never the form.
     */
    public function reschedule(Request $request, SavingsGoal $goal, SavingsGoalService $goals): RedirectResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'plan_term_id' => ['required', 'integer', Rule::exists('plan_terms', 'id')->where('is_active', true)],
        ], [
            'plan_term_id.required' => 'Choose how you want to pay it off.',
            'plan_term_id.exists' => 'That plan length is not offered any more. Pick one from the list.',
        ]);

        $term = PlanTerm::query()->findOrFail($validated['plan_term_id']);

        $goal = $goals->reschedule($request->user(), $goal, $term);

        return back()->with(
            'success',
            'Schedule updated — '.number_format($goal->installment_kobo / 100).' '
                .strtolower($term->cadence->label()).' until it is paid off.',
        );
    }

    /**
     * Point this plan at a different item, carrying the money across.
     *
     * Products arrive as uuids and are resolved here; the price they are
     * worth is read off the row inside the service, never from the request.
     */
    public function switchItem(
        Request $request,
        SavingsGoal $goal,
        SavingsGoalService $goals,
        StartPaystackPaymentAction $startPayment,
    ): SymfonyResponse {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_uuid' => ['required', 'string', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'plan_term_id' => ['nullable', 'integer', Rule::exists('plan_terms', 'id')->where('is_active', true)],
        ], [
            'items.required' => 'Choose something to switch this plan to.',
        ]);

        $lines = collect($validated['items'])->map(function (array $line) {
            $product = Product::query()->where('uuid', $line['product_uuid'])->first();

            if ($product === null) {
                throw ValidationException::withMessages(['items' => 'That item could not be found.']);
            }

            return ['product' => $product, 'quantity' => (int) $line['quantity']];
        });

        $term = isset($validated['plan_term_id'])
            ? PlanTerm::query()->find($validated['plan_term_id'])
            : null;

        $goal = $goals->switchTo($request->user(), $goal, $lines, $term);

        // Already covered by what was paid: nothing more to collect, and the
        // plan is ready to be taken.
        if ($goal->isCovered()) {
            return redirect()
                ->route('savings.goals.show', $goal->uuid)
                ->with('success', 'Plan switched — what you had already paid covers it. Take delivery whenever you are ready.');
        }

        // A term that charges up front expects the first instalment now, so
        // the shortfall is collected before the plan carries on.
        if ($goal->term?->paysUpfront()) {
            return $startPayment->forPlanInstallment($request->user(), $goal, $goal->nextPaymentKobo());
        }

        return redirect()
            ->route('savings.goals.show', $goal->uuid)
            ->with('success', 'Plan switched. Your payments moved across — '
                .number_format($goal->remainingKobo() / 100).' to go.');
    }

    public function cancel(Request $request, SavingsGoal $goal, SavingsGoalService $goals): RedirectResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $carried = $goals->cancel($request->user(), $goal);

        return redirect()->route('savings.index')->with(
            'success',
            $carried > 0
                ? 'Plan cancelled. '.number_format($carried / 100, 2).' is kept as credit for your next plan.'
                : 'Plan cancelled.',
        );
    }

    /**
     * Pause the reminders and automatic debit on a plan.
     *
     * The plan itself keeps running: same frozen price, same amount paid, same
     * status. Only the chasing stops, and only for a bounded window.
     */
    public function pause(Request $request, SavingsGoal $goal, SavingsGoalService $goals): RedirectResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $paused = $goals->pause($request->user(), $goal);

        return back()->with(
            'success',
            'Plan paused. Reminders and automatic payments stop until '
                .$paused->pauseExpiresAt()?->toFormattedDateString()
                .', and your price stays locked.',
        );
    }

    public function resume(Request $request, SavingsGoal $goal, SavingsGoalService $goals): RedirectResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $goals->resume($request->user(), $goal);

        return back()->with('success', 'Plan resumed. Reminders and automatic payments are back on.');
    }

    /**
     * What the plan page needs to draw the automatic-payments card.
     *
     * `canEnable` is the honest question the screen has to answer: automatic
     * debit needs a card the customer has already paid with, because that is
     * the only way a reusable authorization exists at all. Offering the switch
     * without one would just fail on submit.
     *
     * Only the last four digits and the card brand are ever sent — the number
     * itself is not stored anywhere in this system.
     *
     * @return array<string, mixed>
     */
    private function automaticDebitPayload(SavingsGoal $goal): array
    {
        $debit = AutomaticDebit::query()
            ->with('authorization')
            ->where('savings_goal_id', $goal->id)
            ->first();

        $savedCard = PaymentAuthorization::query()
            ->where('user_id', $goal->user_id)
            ->where('active', true)
            ->where('reusable', true)
            ->latest('id')
            ->first();

        return [
            'status' => $debit?->status->value ?? 'off',
            'statusLabel' => $debit?->status->label() ?? 'Off',
            'isOn' => (bool) $debit?->isActive(),
            'needsReauthorization' => (bool) $debit?->needsReauthorization(),
            'amountKobo' => $debit?->amount_kobo ?? $goal->installment_kobo,
            'nextRunAt' => $debit?->next_run_at?->format('j M Y'),
            'lastError' => $debit?->last_error,
            'cardLast4' => $debit?->authorization?->last4 ?? $savedCard?->last4,
            'cardBrand' => $debit?->authorization?->card_type ?? $savedCard?->card_type,
            'canEnable' => $goal->isSaving() && $savedCard !== null,
            'hasSavedCard' => $savedCard !== null,
        ];
    }

    /**
     * Turn automatic instalments on, or re-point them at a newly saved card
     * after the old one stopped working.
     */
    public function enableAutomaticDebit(
        Request $request,
        SavingsGoal $goal,
        AutomaticDebitService $debits,
    ): RedirectResponse {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $debit = $debits->enable($request->user(), $goal);

        return back()->with(
            'success',
            'Automatic payments are on. '.number_format($debit->amount_kobo / 100, 2)
                .' will be taken from your saved card each time an instalment falls due.',
        );
    }

    public function disableAutomaticDebit(
        Request $request,
        SavingsGoal $goal,
        AutomaticDebitService $debits,
    ): RedirectResponse {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $debits->disable($request->user(), $goal);

        return back()->with('success', 'Automatic payments are off. You can still pay by hand any time.');
    }
}
