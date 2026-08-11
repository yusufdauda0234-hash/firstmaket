<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Payments\Models\AutomaticDebit;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\AutomaticDebitStatus;
use App\Shared\Enums\PaystackTransactionStatus;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turning automatic instalments on and off, and running the ones that are due.
 *
 * The crediting rule is the important one: this service never adds money to a
 * plan. It creates the same `PaystackTransaction` row a hosted charge would,
 * asks Paystack to charge the saved card, and stops there. The
 * signature-verified webhook credits the plan, exactly as it does when the
 * customer pays by hand.
 *
 * That is deliberate. Crediting here as well would mean two paths into
 * `paid_kobo` — one of them unverified — and the first delayed webhook would
 * pay the instalment twice.
 */
class AutomaticDebitService
{
    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /**
     * Switch automatic instalments on for a plan.
     *
     * Requires a card the customer has already used successfully: Paystack
     * only issues a reusable authorization after a charge the customer
     * themselves approved, so there is no way to set this up against a card
     * they never consented to.
     */
    public function enable(User $user, SavingsGoal $goal): AutomaticDebit
    {
        if ($goal->user_id !== $user->id) {
            throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
        }

        if (! $goal->isSaving()) {
            throw ValidationException::withMessages(['goal' => 'This plan is no longer running.']);
        }

        $authorization = $this->latestAuthorizationFor($user);

        if ($authorization === null) {
            throw ValidationException::withMessages([
                'authorization' => 'Pay one instalment by card first — that is what saves the card for automatic payments.',
            ]);
        }

        $debit = AutomaticDebit::query()->updateOrCreate(
            ['savings_goal_id' => $goal->id],
            [
                'user_id' => $user->id,
                'payment_authorization_id' => $authorization->id,
                'amount_kobo' => $goal->installment_kobo,
                'status' => AutomaticDebitStatus::Active,
                // The plan's own schedule drives the first charge; there is no
                // separate calendar to drift out of step with.
                'next_run_at' => $goal->next_due_at,
                'failure_count' => 0,
                'last_error' => null,
            ],
        );

        $this->auditLogger->log(
            actor: $user,
            subject: $debit,
            action: 'payments.automatic_debit_enabled',
            newValues: ['savings_goal_uuid' => $goal->uuid, 'amount_kobo' => $debit->amount_kobo],
        );

        return $debit;
    }

    /** Switch it off. The plan itself is untouched. */
    public function disable(User $user, SavingsGoal $goal): void
    {
        $debit = AutomaticDebit::query()->where('savings_goal_id', $goal->id)->first();

        if ($debit === null) {
            return;
        }

        if ($debit->user_id !== $user->id) {
            throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
        }

        $debit->update([
            'status' => AutomaticDebitStatus::Cancelled,
            'next_run_at' => null,
        ]);

        $this->auditLogger->log(
            actor: $user,
            subject: $debit,
            action: 'payments.automatic_debit_disabled',
        );
    }

    /**
     * Point the debit at the customer's newest saved card and start again.
     *
     * This is what clears `needs_reauthorization`: they paid an instalment by
     * hand, which saved a working card, and the standing instruction can pick
     * up from there.
     */
    public function reauthorize(User $user, SavingsGoal $goal): AutomaticDebit
    {
        $debit = AutomaticDebit::query()->where('savings_goal_id', $goal->id)->firstOrFail();

        if ($debit->user_id !== $user->id) {
            throw ValidationException::withMessages(['goal' => 'This plan does not belong to you.']);
        }

        return $this->enable($user, $goal);
    }

    /**
     * Everything due right now.
     *
     * Ordered and chunked by the caller; kept as a query so the scheduler can
     * run it without loading every debit in the system.
     *
     * @return Collection<int, AutomaticDebit>
     */
    public function due(): Collection
    {
        return AutomaticDebit::query()
            ->where('status', AutomaticDebitStatus::Active)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->with(['user', 'goal', 'authorization'])
            ->orderBy('next_run_at')
            ->get();
    }

    /**
     * Attempt one debit.
     *
     * Idempotent in the way that matters for a scheduler: a run is only
     * attempted when `next_run_at` has come round, and every outcome moves
     * that timestamp forward or clears it. Running the command twice in the
     * same minute charges nothing twice.
     *
     * @return 'charged'|'skipped'|'retrying'|'stopped'
     */
    public function attempt(AutomaticDebit $debit): string
    {
        $goal = $debit->goal;
        $user = $debit->user;

        // Reasons to stand down without touching the card. The plan finishing
        // or being paused is not a failure, so none of these burn a retry.
        if ($goal === null || $user === null || ! $goal->isSaving() || $goal->isCovered()) {
            $debit->update(['status' => AutomaticDebitStatus::Cancelled, 'next_run_at' => null]);

            return 'skipped';
        }

        // "Pause stops reminders and automatic debit only" — this is the
        // automatic debit half. The schedule is nudged past the pause instead
        // of being cleared, so resuming needs no repair.
        if ($goal->isPaused()) {
            $debit->update(['next_run_at' => $goal->pauseExpiresAt()]);

            return 'skipped';
        }

        $authorization = $debit->authorization;

        if ($authorization === null || ! $authorization->active) {
            return $this->stop($debit, 'The saved card is no longer available.');
        }

        // Never charge more than the plan still needs.
        $amountKobo = min($debit->amount_kobo, $goal->remainingKobo());

        if ($amountKobo <= 0) {
            $debit->update(['status' => AutomaticDebitStatus::Cancelled, 'next_run_at' => null]);

            return 'skipped';
        }

        $reference = 'FMA_'.Str::lower((string) Str::ulid());

        // Recorded before the charge, exactly as StartPaystackPaymentAction
        // does, so the webhook can tell what the money is for from our own row
        // rather than from anything echoed back to us.
        PaystackTransaction::query()->create([
            'user_id' => $user->id,
            'purpose' => 'plan_installment',
            'savings_goal_id' => $goal->id,
            'paystack_reference' => $reference,
            'amount_kobo' => $amountKobo,
            'currency' => 'NGN',
            'status' => PaystackTransactionStatus::Pending,
        ]);

        $attempt = $this->gateway->chargeAuthorization(
            user: $user,
            authorizationCode: $authorization->authorization_code,
            amountKobo: $amountKobo,
            reference: $reference,
        );

        /*
         * A charge the bank has accepted but not finished is deliberately not
         * a failure.
         *
         * Counting it as one would schedule a retry for tomorrow while the
         * original charge is still live — and if it then completes, the
         * customer has paid one instalment twice. The schedule is therefore
         * advanced exactly as it is for a success, and the webhook decides
         * what actually happened. If the charge ultimately fails, the plan
         * simply falls behind and is chased the ordinary way; that is a far
         * cheaper mistake than taking the money twice.
         */
        if ($attempt->isInFlight()) {
            $debit->update([
                'last_run_at' => now(),
                'last_error' => null,
                'next_run_at' => $this->nextRunAfterSuccess($goal),
            ]);

            return 'charged';
        }

        if (! $attempt->succeeded) {
            return $this->recordFailure($debit, $attempt->message ?? 'The card was declined.');
        }

        // Charged. The plan is credited by the webhook, not here.
        $debit->update([
            'last_run_at' => now(),
            'last_succeeded_at' => now(),
            'failure_count' => 0,
            'last_error' => null,
            'next_run_at' => $this->nextRunAfterSuccess($goal),
        ]);

        return 'charged';
    }

    /**
     * One failure, then one retry a day later, then stop and ask for the card.
     *
     * @return 'retrying'|'stopped'
     */
    private function recordFailure(AutomaticDebit $debit, string $reason): string
    {
        $failures = $debit->failure_count + 1;

        if ($failures >= AutomaticDebit::maxFailures()) {
            return $this->stop($debit, $reason, $failures);
        }

        $debit->update([
            'last_run_at' => now(),
            'last_failed_at' => now(),
            'failure_count' => $failures,
            'last_error' => Str::limit($reason, 250),
            'next_run_at' => now()->addHours(AutomaticDebit::retryAfterHours()),
        ]);

        return 'retrying';
    }

    /** Stop charging and wait for a fresh card. */
    private function stop(AutomaticDebit $debit, string $reason, ?int $failures = null): string
    {
        $debit->update([
            'status' => AutomaticDebitStatus::NeedsReauthorization,
            'last_run_at' => now(),
            'last_failed_at' => now(),
            'failure_count' => $failures ?? $debit->failure_count,
            'last_error' => Str::limit($reason, 250),
            'next_run_at' => null,
        ]);

        return 'stopped';
    }

    /**
     * When the next instalment is due.
     *
     * Read from the plan where it has one, so the debit follows the schedule
     * the customer actually agreed to. `next_due_at` is advanced by
     * SavingsGoalService when the webhook credits the payment, so at this
     * moment it is still the date just charged for — hence the cadence step.
     */
    private function nextRunAfterSuccess(SavingsGoal $goal): ?\Illuminate\Support\Carbon
    {
        $from = $goal->next_due_at ?? now();
        $cadence = $goal->cadence;

        if ($cadence === null) {
            return null;
        }

        $next = $cadence->next($from->copy());

        // A schedule that has fallen behind would otherwise charge again
        // immediately on the next run.
        return $next->isPast() ? now()->addDay() : $next;
    }

    private function latestAuthorizationFor(User $user): ?PaymentAuthorization
    {
        return PaymentAuthorization::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('reusable', true)
            ->latest('id')
            ->first();
    }
}
