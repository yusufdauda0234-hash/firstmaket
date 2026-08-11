<?php

namespace App\Modules\Payments\Commands;

use App\Modules\Payments\Services\PaymentReconciler;
use Illuminate\Console\Command;

/**
 * Catches payments the webhook never told us about, and clears out the
 * attempts that will never become money.
 *
 * The on-demand path in StartPaystackPaymentAction only fires when a
 * customer comes back to pay again. Plenty of them never do — they see the
 * plan still showing unpaid, assume it failed, and either give up or call
 * support. This sweep finds those on its own, so the fix does not depend on
 * the customer having the patience to try a second time.
 */
class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Ask Paystack about unresolved charges: credit any that succeeded, drop any that are dead.';

    public function handle(PaymentReconciler $reconciler): int
    {
        $result = $reconciler->sweep();

        $this->info(sprintf(
            'Reconciled: %d credited, %d removed, %d still in flight.',
            $result['settled'],
            $result['removed'],
            $result['inFlight'],
        ));

        return self::SUCCESS;
    }
}
