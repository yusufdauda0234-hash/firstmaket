<?php

namespace App\Modules\Savings\Commands;

use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Console\Command;

/**
 * Revoke plans whose first payment never arrived.
 *
 * A plan holds a price against today's rate, so one that is never paid into
 * cannot sit open forever. The deadline is stamped on the plan when it
 * starts, from the term the customer chose; this only acts on plans that are
 * past it.
 *
 * Idempotent — a revoked plan clears its deadline, so a second run finds
 * nothing.
 */
class RevokeUnpaidPlans extends Command
{
    protected $signature = 'firstmaket:revoke-unpaid-plans {--dry-run : List what would be revoked without touching it}';

    protected $description = 'Cancel Pay Small Small plans whose first payment is overdue, carrying anything paid to credit';

    public function handle(SavingsGoalService $goals): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $overdue = SavingsGoal::query()
            ->where('status', SavingsGoalStatus::Saving)
            ->whereNotNull('first_payment_due_at')
            ->where('first_payment_due_at', '<=', now())
            ->with('user')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No plans are past their first payment deadline.');

            return self::SUCCESS;
        }

        $revoked = 0;
        $carried = 0;

        foreach ($overdue as $goal) {
            if ($dryRun) {
                $this->line(sprintf(
                    '  would revoke %s (due %s, paid %s)',
                    $goal->uuid,
                    $goal->first_payment_due_at?->toDateTimeString() ?? '—',
                    number_format($goal->paid_kobo / 100, 2),
                ));
                $revoked++;

                continue;
            }

            $carried += $goals->revokeForMissedFirstPayment($goal);
            $revoked++;
        }

        $this->info(sprintf(
            '%s %d plan%s%s.',
            $dryRun ? 'Would revoke' : 'Revoked',
            $revoked,
            $revoked === 1 ? '' : 's',
            $carried > 0 ? ', carrying ₦'.number_format($carried / 100).' to credit' : '',
        ));

        return self::SUCCESS;
    }
}
