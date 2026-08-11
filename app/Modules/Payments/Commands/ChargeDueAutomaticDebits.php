<?php

namespace App\Modules\Payments\Commands;

use App\Modules\Payments\Services\AutomaticDebitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 2B: charge the automatic instalments that have come due.
 *
 * Idempotent. A debit is only picked up when its `next_run_at` has passed, and
 * every outcome — charged, declined, skipped — moves that timestamp forward or
 * clears it before the next row is touched. Running this twice in the same
 * minute therefore charges nothing twice, which matters because a scheduler
 * that overlaps or a deploy that replays it must never take a customer's money
 * again.
 *
 * One customer's failure never stops the run: a thrown exception is logged
 * against that debit and the loop carries on, or everybody queued behind a
 * single bad card would go uncharged.
 */
class ChargeDueAutomaticDebits extends Command
{
    protected $signature = 'firstmaket:charge-automatic-debits {--dry-run : List what would be charged without charging anything}';

    protected $description = 'Charge saved cards for Pay Small Small instalments that are due';

    public function handle(AutomaticDebitService $debits): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $due = $debits->due();

        if ($due->isEmpty()) {
            $this->info('No automatic debits are due.');

            return self::SUCCESS;
        }

        $tally = ['charged' => 0, 'retrying' => 0, 'stopped' => 0, 'skipped' => 0, 'errored' => 0];

        foreach ($due as $debit) {
            if ($dryRun) {
                $this->line(sprintf(
                    '  would charge %s kobo for plan %s',
                    number_format($debit->amount_kobo),
                    $debit->goal?->uuid ?? '(missing plan)',
                ));

                continue;
            }

            try {
                $outcome = $debits->attempt($debit);
                $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
            } catch (Throwable $e) {
                $tally['errored']++;

                // Logged, not rethrown — see the class note.
                Log::error('Automatic debit failed to run.', [
                    'automatic_debit_id' => $debit->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if ($dryRun) {
            $this->info($due->count().' automatic debit(s) are due.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d charged, %d retrying, %d awaiting a new card, %d skipped, %d errored.',
            $tally['charged'],
            $tally['retrying'],
            $tally['stopped'],
            $tally['skipped'],
            $tally['errored'],
        ));

        return self::SUCCESS;
    }
}
