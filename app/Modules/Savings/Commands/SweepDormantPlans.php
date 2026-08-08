<?php

namespace App\Modules\Savings\Commands;

use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Console\Command;

/**
 * Warn, then let go of, plans the customer stopped paying into.
 *
 * A plan holds a price against inflation, so one nobody is paying cannot stay
 * open forever. How many payments may be missed is set per term by an admin
 * and snapshotted onto the plan at signup.
 *
 * Two passes, never both in one run: a plan past its allowance is warned, and
 * only revoked on a later run. That guarantees nobody loses a plan without
 * notice, and one payment at any point clears the warning.
 *
 * Idempotent — a warned plan is not warned again, and a revoked one no longer
 * matches.
 */
class SweepDormantPlans extends Command
{
    protected $signature = 'firstmaket:sweep-dormant-plans {--dry-run : List what would happen without touching anything}';

    protected $description = 'Warn plans that have missed too many payments, and close ones already warned';

    public function handle(SavingsGoalService $goals): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Narrowed in SQL to plans with an overdue schedule and an allowance
        // at all; the exact miss count needs the cadence, so it is judged per
        // plan in isDormant().
        $candidates = SavingsGoal::query()
            ->where('status', SavingsGoalStatus::Saving)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', now())
            ->whereNotNull('missed_payments_allowed')
            ->where('missed_payments_allowed', '>', 0)
            ->with('user')
            ->get()
            ->filter(fn (SavingsGoal $goal) => $goal->isDormant());

        if ($candidates->isEmpty()) {
            $this->info('No plans have missed more payments than their term allows.');

            return self::SUCCESS;
        }

        $warned = 0;
        $revoked = 0;
        $carried = 0;

        foreach ($candidates as $goal) {
            $alreadyWarned = $goal->dormancy_warned_at !== null;

            if ($dryRun) {
                $this->line(sprintf(
                    '  would %s %s (%d missed, allowance %d)',
                    $alreadyWarned ? 'close' : 'warn',
                    $goal->uuid,
                    $goal->missedPayments(),
                    (int) $goal->missed_payments_allowed,
                ));

                $alreadyWarned ? $revoked++ : $warned++;

                continue;
            }

            if ($alreadyWarned) {
                $carried += $goals->revokeDormant($goal);
                $revoked++;
            } elseif ($goals->warnDormant($goal)) {
                $warned++;
            }
        }

        $this->info(sprintf(
            '%s %d, %s %d%s.',
            $dryRun ? 'Would warn' : 'Warned',
            $warned,
            $dryRun ? 'would close' : 'closed',
            $revoked,
            $carried > 0 ? ', carrying ₦'.number_format($carried / 100).' to credit' : '',
        ));

        return self::SUCCESS;
    }
}
