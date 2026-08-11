<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B: let a customer pause a Pay Small Small plan.
 *
 * Deliberately a timestamp rather than a new SavingsGoalStatus case. Pausing
 * changes nothing about the plan itself — not the frozen target, not what has
 * been paid, not the status — it only suspends the reminders and any
 * automatic debit. Modelling it as a status would have made every existing
 * `where('status', Saving)` query silently skip paused plans, which is the
 * opposite of "the plan carries on, we just stop chasing you".
 *
 * The timestamp also dates the pause, which is what lets it expire: see
 * config('firstmaket.savings.max_pause_days').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('dormancy_warned_at');

            // The dormancy sweep and the automatic-debit run both filter on
            // this every time they run.
            $table->index('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropIndex(['paused_at']);
            $table->dropColumn('paused_at');
        });
    }
};
