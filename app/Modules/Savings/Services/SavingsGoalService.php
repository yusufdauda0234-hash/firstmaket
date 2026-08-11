<?php

namespace App\Modules\Savings\Services;

use App\Modules\Savings\Events\PlanCompleted;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Logistics\Services\ShipmentBuilder;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryPricing;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Models\SavingsGoalItem;
use App\Modules\Savings\Notifications\PlanDormantNotification;
use App\Modules\Savings\Notifications\PlanRevokedNotification;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pay Small Small plans: the cart, frozen at today's prices, paid off in
 * installments.
 *
 * Money only ever exists inside a plan — there is no customer balance to top
 * up. Each payment is a Paystack charge recorded against this plan, and the
 * plan's own paid_kobo is the single source of truth for progress. When it
 * covers the target the customer can take delivery, and orders are created
 * through the same CheckoutSession + OrderService path as an ordinary
 * checkout, at the frozen price.
 *
 * Cancelling leaves whatever was paid as plan credit
 * (SavingsService::creditFromCancelledPlan), which can only be spent on
 * another plan — never withdrawn.
 */
class SavingsGoalService
{
    public function __construct(
        private readonly SavingsService $savings,
        private readonly OrderService $orderService,
        private readonly ShipmentBuilder $shipmentBuilder,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /**
     * Start a plan from cart lines on the chosen term, locking today's
     * prices and the schedule.
     *
     * @param  Collection<int, array{cartItemId: int|null, product: Product, quantity: int}>  $lines
     * @param  array<string, mixed>  $address  Validated delivery fields.
     */
    public function createFromLines(User $user, Collection $lines, array $address, PlanTerm $term): SavingsGoal
    {
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'There is nothing to start a plan for.']);
        }

        // Open bundling is the current product decision. Keeping the switch
        // in settings makes a future eligibility gate a policy change, not a
        // rewrite of plan pricing and fulfilment.
        if ($lines->count() > 1 && ! (bool) Setting::get('savings.multi_product_plans_enabled', true)) {
            throw ValidationException::withMessages([
                'cart' => 'Multi-product plans are not available yet.',
            ]);
        }

        return DB::transaction(function () use ($user, $lines, $address, $term) {
            $goodsKobo = (int) $lines->sum(fn (array $line) => $line['product']->price_kobo * $line['quantity']);

            // Delivery is part of the plan, quoted from the same rates a card
            // checkout uses and locked here with the prices. A plan runs for
            // months; a customer who agreed to a target must not owe more
            // because a rate moved while they were paying it off.
            $deliveryKobo = app(DeliveryPricing::class)->feeKobo($goodsKobo, $address['state'] ?? null);

            $targetKobo = $goodsKobo + $deliveryKobo;

            if ($targetKobo < $term->min_target_kobo) {
                throw ValidationException::withMessages([
                    'plan_term_id' => 'This plan is only available on orders above '
                        .number_format($term->min_target_kobo / 100, 2).'.',
                ]);
            }

            $goal = SavingsGoal::query()->create([
                'user_id' => $user->id,
                'target_kobo' => $targetKobo,
                // Kept apart from the goods so the plan page can show the
                // customer what they are paying for, and so fulfilment can
                // put the right figure on the order.
                'delivery_fee_kobo' => $deliveryKobo,
                // Snapshot the term: retiring or editing it later must not
                // move the goalposts on a plan already running.
                'plan_term_id' => $term->id,
                'cadence' => $term->cadence,
                'installments' => $term->installments,
                'duration_months' => $term->duration_months,
                'installment_kobo' => $term->installmentKoboFor($targetKobo),
                'paid_kobo' => 0,
                'started_at' => now(),
                'next_due_at' => $term->cadence->next(now()),
                // Snapshotted, like the rest of the term: the deadline a
                // customer agreed to must not move when an admin edits the
                // term later. Same for the missed-payment allowance.
                'first_payment_due_at' => $term->firstPaymentDueAt(),
                'missed_payments_allowed' => $term->missed_payments_allowed,
                'status' => SavingsGoalStatus::Saving,
                'delivery_address' => $address['delivery_address'],
                'state' => $address['state'],
                'lga' => $address['lga'],
                'recipient_name' => $address['recipient_name'] ?? null,
                'recipient_phone' => $address['recipient_phone'] ?? null,
                'landmark' => $address['landmark'] ?? null,
            ]);

            foreach ($lines as $line) {
                $goal->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price_kobo' => $line['product']->price_kobo,
                ]);
            }

            // Credit left over from a cancelled plan rolls straight in, so
            // the customer never has to think about where it went.
            $credit = $this->savings->creditKobo($user);
            if ($credit > 0) {
                $this->recordPayment($user, $goal, min($credit, $targetKobo), 'credit');
                $this->savings->consumeCredit($user, min($credit, $targetKobo), $goal->uuid);
            }

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_started',
                newValues: [
                    'target_kobo' => $targetKobo,
                    'cadence' => $term->cadence->value,
                    'installments' => $term->installments,
                ],
            );

            return $goal->refresh();
        });
    }

    /**
     * Record money arriving on a plan. Called by the Paystack webhook once a
     * charge is verified — never straight from a browser request.
     */
    public function recordPayment(User $user, SavingsGoal $goal, int $amountKobo, string $source = 'card', ?string $reference = null): PlanPayment
    {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payment must be greater than zero.']);
        }

        $reference ??= 'PLAN-'.Str::uuid()->toString();

        return DB::transaction(function () use ($user, $goal, $amountKobo, $source, $reference) {
            // Idempotency: a replayed webhook must not pay the plan twice.
            $existing = PlanPayment::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                return $existing;
            }

            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            $before = $goal->paid_kobo;
            // Never bank more than the plan is worth.
            $amountKobo = min($amountKobo, $goal->remainingKobo() ?: $amountKobo);
            $after = $before + $amountKobo;

            $payment = PlanPayment::query()->create([
                'savings_goal_id' => $goal->id,
                'user_id' => $user->id,
                'amount_kobo' => $amountKobo,
                'paid_before_kobo' => $before,
                'paid_after_kobo' => $after,
                'source' => $source,
                'reference' => $reference,
            ]);

            $goal->forceFill([
                'paid_kobo' => $after,
                // Counted rather than derived, so a later reschedule onto a
                // smaller instalment cannot invent payments.
                'payments_made' => $goal->payments_made + 1,
                // Only push the due date out once the plan is still running.
                'next_due_at' => $after >= $goal->target_kobo
                    ? null
                    : $goal->cadence?->next(now()),
                // The first payment has landed, so the plan is no longer at
                // risk of being revoked for never having started.
                'first_payment_due_at' => null,
                // Paying clears any dormancy warning: the schedule has just
                // been pushed forward, so the plan is current again and a
                // later lapse deserves a fresh warning rather than immediate
                // revocation.
                'dormancy_warned_at' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_payment',
                newValues: ['amount_kobo' => $amountKobo, 'paid_after_kobo' => $after, 'source' => $source],
            );

            return $payment;
        });
    }

    /**
     * Take delivery once the plan is fully paid. Orders are raised at the
     * frozen price through the ordinary checkout path.
     *
     * @return Collection<int, Order>
     */
    public function fulfil(User $user, SavingsGoal $goal): Collection
    {
        return DB::transaction(function () use ($user, $goal) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($goal->user_id !== $user->id) {
                throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
            }

            if (! $goal->isSaving()) {
                throw ValidationException::withMessages(['goal' => 'This plan has already been completed.']);
            }

            if (! $goal->isCovered()) {
                throw ValidationException::withMessages([
                    'goal' => 'This plan is not fully paid yet — '
                        .number_format($goal->remainingKobo() / 100, 2).' still to go.',
                ]);
            }

            $goal->load('items.product');

            // The price is locked, but availability is not — re-check it the
            // same way cart checkout does, because a plan runs for months.
            $items = [];
            foreach ($goal->items as $item) {
                $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->first();

                if ($product === null
                    || $product->status !== ProductStatus::Approved
                    || $item->quantity > $product->stock_quantity
                ) {
                    throw ValidationException::withMessages([
                        'goal' => ($item->product->name ?? 'An item').' is no longer available, so this plan cannot be completed. Your payments are safe — cancel to move them to another product.',
                    ]);
                }

                $items[] = [
                    'product' => $product,
                    'quantity' => $item->quantity,
                    // Honour the frozen price, not today's.
                    'unitPriceKobo' => $item->unit_price_kobo,
                ];
            }

            $session = CheckoutSession::query()->create([
                'user_id' => $user->id,
                // The plan's own payments already settled this; there is no
                // savings ledger entry to point at.
                'savings_transaction_id' => null,
                'total_amount_kobo' => $goal->target_kobo,
                // What the plan actually collected for delivery. Zero only on
                // plans started before delivery was part of the target.
                'shipping_fee_kobo' => $goal->delivery_fee_kobo,
                'payment_method' => 'pay_small_small',
                'delivery_address' => $goal->delivery_address,
                'state' => $goal->state,
                'lga' => $goal->lga,
                'recipient_name' => $goal->recipient_name,
                'recipient_phone' => $goal->recipient_phone,
                'landmark' => $goal->landmark,
                'created_at' => now(),
            ]);

            $orders = $this->orderService->createFromCheckoutSession($user, $session, $items);

            Order::query()->whereIn('id', $orders->pluck('id'))->update(['savings_goal_id' => $goal->id]);

            // A completed plan ships exactly like a card checkout — same
            // parcels, same courier queue, same proof code. The only thing
            // that differed was how it was paid for, and delivery should not
            // be able to tell.
            $this->shipmentBuilder->fromCheckout($session, $orders);

            $goal->forceFill([
                'status' => SavingsGoalStatus::Fulfilled,
                'fulfilled_at' => now(),
                'next_due_at' => null,
            ])->save();

            DB::afterCommit(fn () => PlanCompleted::dispatch(
                $goal->id,
                $goal->user_id,
                $goal->target_kobo,
            ));

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_fulfilled',
                newValues: ['target_kobo' => $goal->target_kobo, 'order_count' => $orders->count()],
            );

            return $orders;
        });
    }

    /**
     * Give up on a plan. Whatever has been paid becomes credit toward
     * another product — it is never refunded as cash and never forfeited.
     */
    public function cancel(User $user, SavingsGoal $goal): int
    {
        return DB::transaction(function () use ($user, $goal) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($goal->user_id !== $user->id) {
                throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
            }

            if (! $goal->isSaving()) {
                throw ValidationException::withMessages(['goal' => 'This plan has already been completed.']);
            }

            $carried = $goal->paid_kobo;

            if ($carried > 0) {
                $this->savings->creditFromCancelledPlan($user, $carried, $goal->uuid);
            }

            $goal->forceFill([
                'status' => SavingsGoalStatus::Cancelled,
                'next_due_at' => null,
                'first_payment_due_at' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_cancelled',
                newValues: ['carried_credit_kobo' => $carried],
            );

            return $carried;
        });
    }

    /**
     * Pause the chasing, not the plan.
     *
     * Suspends the payment reminders, the dormancy sweep and any automatic
     * debit. It deliberately does not touch `target_kobo`, `paid_kobo`,
     * `status` or the schedule: a customer who pauses for a month comes back to
     * exactly the plan they left, at the price they locked.
     *
     * Refused before the first payment. The price freezes at signup, so
     * allowing a pause on a plan nobody has paid into would let anyone lock
     * today's price for free and hold it — which is precisely what
     * RevokeUnpaidPlans exists to prevent.
     */
    public function pause(User $user, SavingsGoal $goal): SavingsGoal
    {
        return DB::transaction(function () use ($user, $goal) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($goal->user_id !== $user->id) {
                throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
            }

            if (! $goal->isSaving()) {
                throw ValidationException::withMessages(['goal' => 'Only a running plan can be paused.']);
            }

            if ($goal->payments_made < 1) {
                throw ValidationException::withMessages([
                    'goal' => 'Make your first payment before pausing this plan.',
                ]);
            }

            if ($goal->isPaused()) {
                return $goal;
            }

            $goal->forceFill([
                'paused_at' => now(),
                // A pause is the customer answering the warning. Leaving it
                // set would revoke the plan on the next sweep after the pause
                // expires, without the second chance the two-pass sweep is
                // meant to guarantee.
                'dormancy_warned_at' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_paused',
                newValues: ['paused_until' => $goal->pauseExpiresAt()?->toDateTimeString()],
            );

            return $goal;
        });
    }

    /** Resume reminders and automatic debit. Nothing else changes. */
    public function resume(User $user, SavingsGoal $goal): SavingsGoal
    {
        return DB::transaction(function () use ($user, $goal) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($goal->user_id !== $user->id) {
                throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
            }

            if ($goal->paused_at === null) {
                return $goal;
            }

            $goal->forceFill(['paused_at' => null])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_resumed',
            );

            return $goal;
        });
    }

    /**
     * Revoke a plan whose first payment never arrived.
     *
     * The customer did not choose this, so it is logged as its own action
     * rather than an ordinary cancellation — but the money rule is identical:
     * anything already paid becomes credit toward another product, never cash
     * back and never forfeited.
     *
     * @return int Kobo carried over as credit.
     */
    public function revokeForMissedFirstPayment(SavingsGoal $goal): int
    {
        return DB::transaction(function () use ($goal) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            // Re-checked under the lock: a payment landing between the sweep
            // and here must win.
            if (! $goal->isSaving() || $goal->first_payment_due_at === null) {
                return 0;
            }

            if ($goal->first_payment_due_at->isFuture()) {
                return 0;
            }

            $user = $goal->user;
            $carried = $goal->paid_kobo;

            if ($carried > 0 && $user !== null) {
                $this->savings->creditFromCancelledPlan($user, $carried, $goal->uuid);
            }

            $goal->forceFill([
                'status' => SavingsGoalStatus::Cancelled,
                'next_due_at' => null,
                'first_payment_due_at' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_revoked_unpaid',
                newValues: [
                    'carried_credit_kobo' => $carried,
                    'due_at' => $goal->getOriginal('first_payment_due_at'),
                ],
            );

            if ($user !== null) {
                $user->notify(new PlanRevokedNotification($goal, $carried));
            }

            return $carried;
        });
    }

    /**
     * How many times one plan may be pointed at something else.
     *
     * A switch re-prices at today's price, so unlimited switching turns a
     * plan into a rolling free option on price movements while FirstMaket
     * holds the customer's money. Two covers a genuine change of mind.
     */
    public const MAX_SWITCHES = 2;

    /**
     * How many times a plan may be switched to a different item.
     *
     * The constant is the shipped default; this is what the code reads, so
     * staff can loosen or tighten it without a deploy.
     */
    public static function maxSwitches(): int
    {
        return max(0, (int) Setting::get('savings.max_plan_switches', self::MAX_SWITCHES));
    }

    /**
     * Point an existing plan at a different item, keeping the money on it.
     *
     * Deliberately not cancel-then-create. Cancelling would write a
     * cancellation into the audit trail for something that was not one, churn
     * the credit ledger for money that never needed to leave the plan, detach
     * the payments from the plan they were made into, and hand the customer a
     * new plan to follow. Everything here happens in place, so one plan keeps
     * one history and one identity.
     *
     * The new item is priced at TODAY's price — the old lock dies with the
     * old item, because there is no honest way to carry a fridge's frozen
     * price onto a television. Callers must say so before confirming.
     *
     * @param  Collection<int, array{product: Product, quantity: int}>  $lines
     * @param  PlanTerm|null  $term  Required only when the plan's current term
     *                               no longer covers the new total.
     */
    public function switchTo(User $user, SavingsGoal $goal, Collection $lines, ?PlanTerm $term = null): SavingsGoal
    {
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Choose something to switch this plan to.']);
        }

        return DB::transaction(function () use ($user, $goal, $lines, $term) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($goal->user_id !== $user->id) {
                throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
            }

            if (! $goal->isSaving()) {
                throw ValidationException::withMessages(['goal' => 'This plan is no longer running.']);
            }

            if ($goal->switch_count >= self::maxSwitches()) {
                throw ValidationException::withMessages([
                    'items' => 'This plan has already been switched '.self::maxSwitches().' times. '
                        .'Finish it, or cancel and start a new one with the money as credit.',
                ]);
            }

            // Prices are re-read from the locked product rows, never taken
            // from the request: the amount a customer owes can only ever come
            // from the catalogue.
            // What the plan already holds, keyed by product, so an item the
            // customer chose to keep can be re-created at the price it was
            // locked at rather than today's.
            $lockedPrices = $goal->items
                ->mapWithKeys(fn (SavingsGoalItem $item) => [$item->product_id => $item->unit_price_kobo]);

            $target = 0;
            $resolved = [];

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = Product::query()->whereKey($line['product']->id)->lockForUpdate()->firstOrFail();
                $quantity = max(1, (int) $line['quantity']);

                if ($product->status !== ProductStatus::Approved) {
                    throw ValidationException::withMessages([
                        'items' => $product->name.' is not on sale right now.',
                    ]);
                }

                if ($quantity > $product->stock_quantity) {
                    throw ValidationException::withMessages([
                        'items' => 'Only '.$product->stock_quantity.' of '.$product->name.' left.',
                    ]);
                }

                // An item already on the plan keeps the price it was locked
                // at — only what the customer actually swaps out loses its
                // lock. The locked figure is read from the plan's own rows,
                // never from the request, so it cannot be claimed for a
                // product that was never on the plan.
                $unitPriceKobo = $lockedPrices[$product->id] ?? $product->price_kobo;

                $target += $unitPriceKobo * $quantity;
                $resolved[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unitPriceKobo' => $unitPriceKobo,
                ];
            }

            // Re-quoted, not carried over: a switch changes the goods total,
            // which can move the basket across a free-delivery threshold or a
            // rate band. Carrying the old fee would charge a customer for a
            // band their new basket is no longer in.
            $deliveryKobo = app(DeliveryPricing::class)->feeKobo($target, $goal->state);
            $target += $deliveryKobo;

            $chosen = $term ?? $goal->term;

            if ($chosen === null || ! $chosen->is_active || $target < $chosen->min_target_kobo) {
                throw ValidationException::withMessages([
                    'plan_term_id' => 'Choose how you want to pay for this — your current plan length is not '
                        .'offered at this price.',
                ]);
            }

            $before = [
                'target_kobo' => $goal->target_kobo,
                'items' => $goal->items->map(fn (SavingsGoalItem $item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price_kobo' => $item->unit_price_kobo,
                ])->all(),
            ];

            $goal->items()->delete();

            foreach ($resolved as $line) {
                $goal->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price_kobo' => $line['unitPriceKobo'],
                ]);
            }

            // Switching to something cheaper than what has been paid must not
            // leave money stranded above the target. The excess becomes credit
            // — spendable on another plan, never cash, exactly as everywhere
            // else.
            $paid = $goal->paid_kobo;
            $excess = max(0, $paid - $target);

            if ($excess > 0) {
                $this->savings->creditFromCancelledPlan($user, $excess, $goal->uuid);
                $paid = $target;
            }

            $remaining = max(0, $target - $paid);
            $covered = $remaining === 0;

            $goal->forceFill([
                'target_kobo' => $target,
                'delivery_fee_kobo' => $deliveryKobo,
                'paid_kobo' => $paid,
                'plan_term_id' => $chosen->id,
                'cadence' => $chosen->cadence,
                'duration_months' => $chosen->duration_months,
                'installments' => $goal->payments_made + ($covered ? 0 : $chosen->installments),
                'installment_kobo' => $covered
                    ? 0
                    : (int) ceil($remaining / max(1, $chosen->installments)),
                'next_due_at' => $covered ? null : $chosen->cadence->next(now()),
                'switch_count' => $goal->switch_count + 1,
                // A fresh schedule, so an old warning no longer describes it.
                'dormancy_warned_at' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_switched',
                oldValues: $before,
                newValues: [
                    'target_kobo' => $target,
                    'items' => array_map(fn (array $line) => [
                        'product_id' => $line['product']->id,
                        'quantity' => $line['quantity'],
                        'unit_price_kobo' => $line['product']->price_kobo,
                    ], $resolved),
                    'credited_excess_kobo' => $excess,
                ],
            );

            return $goal->refresh();
        });
    }

    /**
     * Move a plan onto a different schedule, keeping the item and the price.
     *
     * Only the rhythm changes: `target_kobo` and the frozen item prices are
     * untouched, so this is materially safer than switching the item and is
     * kept as its own operation rather than folded into one.
     *
     * The remaining balance is what gets rescheduled, not the original
     * target. A customer who has paid three of four and stretches to a year
     * is spreading what is left, and dividing the whole target again would
     * quietly ask them to pay it twice.
     *
     * Extending is capped because it stretches how long FirstMaket holds a
     * price against inflation. Shortening is always allowed — a customer can
     * already finish early by overpaying.
     */
    public function reschedule(User $user, SavingsGoal $goal, PlanTerm $term): SavingsGoal
    {
        return DB::transaction(function () use ($user, $goal, $term) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($goal->user_id !== $user->id) {
                throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
            }

            if (! $goal->isSaving()) {
                throw ValidationException::withMessages(['goal' => 'This plan is no longer running.']);
            }

            if (! $term->is_active) {
                throw ValidationException::withMessages([
                    'plan_term_id' => 'That plan length is not offered any more. Pick one from the list.',
                ]);
            }

            if ($goal->target_kobo < $term->min_target_kobo) {
                throw ValidationException::withMessages([
                    'plan_term_id' => 'That plan length is only offered on orders above ₦'
                        .number_format($term->min_target_kobo / 100).'.',
                ]);
            }

            $current = (int) ($goal->duration_months ?? 0);
            $isExtension = $term->duration_months > $current;

            if ($isExtension) {
                if ($goal->extension_count > 0) {
                    throw ValidationException::withMessages([
                        'plan_term_id' => 'This plan has already been extended once. You can still pay it off '
                            .'faster, or switch it to something else.',
                    ]);
                }

                if ($goal->missedPayments() > 0) {
                    throw ValidationException::withMessages([
                        'plan_term_id' => 'Catch up on the payments you have missed first, then this plan can '
                            .'be extended.',
                    ]);
                }

                $longest = (int) PlanTerm::query()->where('is_active', true)->max('duration_months');

                if ($term->duration_months > $longest) {
                    throw ValidationException::withMessages([
                        'plan_term_id' => 'That is longer than any plan we offer.',
                    ]);
                }
            }

            $remaining = $goal->remainingKobo();

            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'goal' => 'This plan is already paid off — there is nothing left to reschedule.',
                ]);
            }

            $before = [
                'cadence' => $goal->cadence?->value,
                'installments' => $goal->installments,
                'duration_months' => $current,
                'installment_kobo' => $goal->installment_kobo,
            ];

            $goal->forceFill([
                'plan_term_id' => $term->id,
                'cadence' => $term->cadence,
                'duration_months' => $term->duration_months,
                // Payments already made plus the run still to go, so the
                // "3 of 15" the customer sees stays true.
                'installments' => $goal->payments_made + $term->installments,
                'installment_kobo' => (int) ceil($remaining / max(1, $term->installments)),
                'next_due_at' => $term->cadence->next(now()),
                'extension_count' => $goal->extension_count + ($isExtension ? 1 : 0),
                // The schedule has just been reset, so any dormancy warning
                // no longer describes this plan.
                'dormancy_warned_at' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_rescheduled',
                oldValues: $before,
                newValues: [
                    'cadence' => $term->cadence->value,
                    'installments' => $goal->installments,
                    'duration_months' => $term->duration_months,
                    'installment_kobo' => $goal->installment_kobo,
                    'extension' => $isExtension,
                ],
            );

            return $goal->refresh();
        });
    }

    /**
     * Warn a customer that their plan is about to be let go.
     *
     * Nobody should lose a plan without being told first, so the sweep warns
     * on one pass and only revokes on a later one. Returns false when a
     * warning was already sent, or the plan has since been paid or settled.
     */
    public function warnDormant(SavingsGoal $goal): bool
    {
        return DB::transaction(function () use ($goal) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if (! $goal->isSaving() || ! $goal->isDormant() || $goal->dormancy_warned_at !== null) {
                return false;
            }

            $goal->forceFill(['dormancy_warned_at' => now()])->save();

            $this->auditLogger->log(
                actor: $goal->user,
                subject: $goal,
                action: 'savings.plan_dormancy_warned',
                newValues: ['missed_payments' => $goal->missedPayments()],
            );

            $goal->user?->notify(new PlanDormantNotification($goal));

            return true;
        });
    }

    /**
     * Let go of a plan the customer stopped paying into.
     *
     * Same money rule as every other exit: whatever was paid becomes credit
     * toward another product, never cash. Only ever called on a plan that has
     * already been warned, so this is never the customer's first notice.
     *
     * @return int Kobo carried over as credit.
     */
    public function revokeDormant(SavingsGoal $goal): int
    {
        return DB::transaction(function () use ($goal) {
            /** @var SavingsGoal $goal */
            $goal = SavingsGoal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            // Re-checked under the lock: a payment landing between the sweep
            // and here clears the dormancy and must win.
            if (! $goal->isSaving() || ! $goal->isDormant() || $goal->dormancy_warned_at === null) {
                return 0;
            }

            $missed = $goal->missedPayments();
            $user = $goal->user;
            $carried = $goal->paid_kobo;

            if ($carried > 0 && $user !== null) {
                $this->savings->creditFromCancelledPlan($user, $carried, $goal->uuid);
            }

            $goal->forceFill([
                'status' => SavingsGoalStatus::Cancelled,
                'next_due_at' => null,
                'first_payment_due_at' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $goal,
                action: 'savings.plan_revoked_dormant',
                newValues: ['carried_credit_kobo' => $carried, 'missed_payments' => $missed],
            );

            $user?->notify(new PlanRevokedNotification($goal, $carried));

            return $carried;
        });
    }
}
