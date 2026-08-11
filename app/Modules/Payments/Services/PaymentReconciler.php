<?php

namespace App\Modules\Payments\Services;

use App\Models\Setting;
use App\Modules\Payments\Actions\ProcessPaystackWebhook;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\TransactionSnapshot;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Settles the question a missing webhook leaves open: did that payment
 * actually go through?
 *
 * A webhook can be dropped, blocked by a firewall, or sent while the app is
 * restarting. When that happens the money has moved but our records say it
 * has not, and the customer is left looking at an unpaid plan they know they
 * paid. Waiting for them to open a support ticket is not a mechanism.
 *
 * So before a customer is sent to pay again, and on a schedule regardless,
 * every unresolved attempt is put to Paystack directly. Three outcomes, and
 * they are deliberately not symmetrical because getting them wrong costs
 * real money in opposite directions:
 *
 *  - **It succeeded.** Credit it through the exact same code the webhook
 *    runs, so the customer is never charged a second time for something they
 *    already paid. The transaction keeps its record forever.
 *  - **It is still in flight.** Do nothing at all. The bank has not
 *    finished; removing the row now would orphan a payment about to land.
 *  - **It is dead** — failed, abandoned, or Paystack has no record of it.
 *    Only then is the row deleted. It can never become money, so keeping it
 *    would just accumulate noise.
 *
 * The one rule that must never bend: **an unreachable provider is not a
 * failure.** A timeout tells us nothing, and deleting a payment record on
 * the strength of "nothing" is how a business loses evidence that somebody
 * paid it. {@see TransactionSnapshot::isDead()} is false whenever the
 * provider could not be reached, and every deletion below goes through it.
 */
class PaymentReconciler
{
    private const DEFAULTS = [
        /*
         * How long an unpaid attempt is left alone before the sweep will
         * consider it.
         *
         * Not zero: a customer can sit on Paystack's page for a while, and a
         * bank transfer can take minutes to confirm. Reconciling a charge
         * somebody is midway through would ask Paystack about a payment that
         * has not been made yet and get "abandoned" for an answer.
         */
        'payments.reconcile_after_minutes' => 30,
        /*
         * Ceiling on how many attempts one scheduled run will verify.
         *
         * Each one is an outbound HTTP call, and Paystack rate-limits. A
         * backlog is worked through over several runs rather than in one
         * burst that gets throttled halfway and leaves an unpredictable
         * subset done.
         */
        'payments.reconcile_batch_size' => 200,
    ];

    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly ProcessPaystackWebhook $webhook,
    ) {}

    public function staleAfterMinutes(): int
    {
        return max(1, (int) Setting::get('payments.reconcile_after_minutes', self::DEFAULTS['payments.reconcile_after_minutes']));
    }

    /**
     * Resolve every unfinished attempt for one thing being paid for.
     *
     * Called before a customer is handed to Paystack again. If one of their
     * earlier attempts actually succeeded, this credits it and reports back
     * so the caller can say "you have already paid for this" instead of
     * taking the money twice.
     *
     * @param  array{checkout_session_id?: int, savings_goal_id?: int, shipment_id?: int}  $subject
     * @return array{settled: int, removed: int, inFlight: int}
     */
    public function reconcileSubject(int $userId, array $subject): array
    {
        $query = PaystackTransaction::query()
            ->where('user_id', $userId)
            ->where('status', PaystackTransactionStatus::Pending)
            ->whereNull('webhook_verified_at');

        foreach ($subject as $column => $value) {
            $query->where($column, $value);
        }

        return $this->reconcileAll($query->get());
    }

    /**
     * The scheduled sweep: everything old enough to have settled by now.
     *
     * @return array{settled: int, removed: int, inFlight: int}
     */
    public function sweep(): array
    {
        $batch = max(1, (int) Setting::get('payments.reconcile_batch_size', self::DEFAULTS['payments.reconcile_batch_size']));

        $stale = PaystackTransaction::query()
            ->where('status', PaystackTransactionStatus::Pending)
            ->whereNull('webhook_verified_at')
            ->where('created_at', '<=', now()->subMinutes($this->staleAfterMinutes()))
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $result = $this->reconcileAll($stale);

        if ($result['settled'] > 0 || $result['removed'] > 0) {
            // One line per run, not per row. The point of this service is to
            // stop accumulating a record of every abandoned attempt; replacing
            // that with a log line per attempt would defeat it.
            Log::info('Payment reconciliation swept.', $result);
        }

        return $result;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PaystackTransaction>  $transactions
     * @return array{settled: int, removed: int, inFlight: int}
     */
    private function reconcileAll($transactions): array
    {
        $settled = 0;
        $removed = 0;
        $inFlight = 0;

        foreach ($transactions as $transaction) {
            match ($this->reconcile($transaction)) {
                'settled' => $settled++,
                'removed' => $removed++,
                default => $inFlight++,
            };
        }

        return ['settled' => $settled, 'removed' => $removed, 'inFlight' => $inFlight];
    }

    /**
     * Resolve one attempt.
     *
     * @return string 'settled' | 'removed' | 'unresolved'
     */
    public function reconcile(PaystackTransaction $transaction): string
    {
        // Belt and braces: something already credited is never re-examined,
        // and never removed.
        if ($transaction->webhook_verified_at !== null
            || $transaction->status === PaystackTransactionStatus::Success
        ) {
            return 'unresolved';
        }

        $snapshot = $this->gateway->verifyTransaction($transaction->paystack_reference);

        if ($snapshot->succeeded()) {
            return $this->settle($transaction, $snapshot) ? 'settled' : 'unresolved';
        }

        // isDead() is false for an unreachable provider, so a timeout can
        // never reach this branch. That is the whole safety property.
        if ($snapshot->isDead()) {
            $transaction->delete();

            return 'removed';
        }

        return 'unresolved';
    }

    /**
     * Credit a payment we have just discovered succeeded.
     *
     * Deliberately routed through ProcessPaystackWebhook rather than
     * reimplemented. The webhook already knows how to credit every purpose
     * we sell, checks the currency and the amount, and refuses to run twice —
     * and a second implementation of "give the customer their money" is a
     * second place for the two to disagree.
     *
     * `signatureValid: true` is not a lie by omission: this payload did not
     * arrive over the webhook at all, it was fetched from Paystack over an
     * authenticated outbound call, which is a stronger guarantee of
     * provenance than a shared-secret HMAC on an inbound request.
     */
    private function settle(PaystackTransaction $transaction, TransactionSnapshot $snapshot): bool
    {
        $payload = $snapshot->payload;
        $payload['reference'] = $transaction->paystack_reference;
        $payload['status'] = 'success';
        $payload['amount'] = $snapshot->amountKobo ?: $transaction->amount_kobo;
        $payload['currency'] = $payload['currency'] ?? 'NGN';
        $payload['channel'] = $snapshot->channel ?? ($payload['channel'] ?? null);
        // Marked so a later dispute can tell a webhook-credited payment from
        // one we had to go and find.
        $payload['firstmaket_reconciled'] = true;

        $event = DB::transaction(fn () => $this->webhook->handle(
            ['event' => 'charge.success', 'data' => $payload],
            signatureValid: true,
        ));

        return $event->processing_status === 'processed';
    }
}
