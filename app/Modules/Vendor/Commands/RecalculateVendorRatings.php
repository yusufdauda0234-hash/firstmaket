<?php

namespace App\Modules\Vendor\Commands;

use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Services\VendorRatingService;
use App\Shared\Enums\VendorStatus;
use Illuminate\Console\Command;

/**
 * Recompute every approved vendor's performance tier.
 *
 * Safe to run as often as you like: the score is a pure function of stored
 * facts, so a second run on unchanged data writes the same numbers and adds no
 * snapshot. Only a genuine tier change is recorded in the history.
 */
class RecalculateVendorRatings extends Command
{
    protected $signature = 'firstmaket:recalculate-vendor-ratings';

    protected $description = 'Recompute vendor performance scores and tiers';

    public function handle(VendorRatingService $ratings): int
    {
        $count = 0;

        VendorProfile::query()
            ->where('status', VendorStatus::Approved)
            ->chunkById(100, function ($vendors) use ($ratings, &$count) {
                foreach ($vendors as $vendor) {
                    $ratings->recalculate($vendor);
                    $count++;
                }
            });

        $this->info($count.' vendor rating(s) recalculated.');

        return self::SUCCESS;
    }
}
