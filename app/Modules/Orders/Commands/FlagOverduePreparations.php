<?php

namespace App\Modules\Orders\Commands;

use App\Modules\Orders\Services\PreparationService;
use Illuminate\Console\Command;

/**
 * Preparation SLA watchdog (docs/FirstMaket_Implementation_Plan.md Sprint
 * 6): flags Processing orders past prepare_due_at to admin exactly once.
 * Scheduled hourly in routes/console.php.
 */
class FlagOverduePreparations extends Command
{
    protected $signature = 'orders:flag-overdue-preparation';

    protected $description = 'Flag vendor preparations that have missed the packing SLA';

    public function handle(PreparationService $preparationService): int
    {
        $flagged = $preparationService->flagOverduePreparations();

        $this->info("Flagged {$flagged} overdue preparation(s).");

        return self::SUCCESS;
    }
}
