<?php

namespace App\Modules\Risk\Commands;

use App\Modules\Risk\Services\RiskFlagService;
use Illuminate\Console\Command;

/**
 * Raise the risk patterns staff have asked to be told about.
 *
 * Idempotent: an open flag for the same rule and subject already exists, so a
 * second run in the same day adds nothing. Nothing here suspends, blocks or
 * cancels anything — it fills a queue for a human.
 */
class SweepRiskFlags extends Command
{
    protected $signature = 'firstmaket:sweep-risk-flags';

    protected $description = 'Raise risk flags for staff review';

    public function handle(RiskFlagService $risk): int
    {
        $raised = $risk->sweep();

        $this->info($raised === 0 ? 'No new risk flags.' : $raised.' risk flag(s) raised for review.');

        return self::SUCCESS;
    }
}
