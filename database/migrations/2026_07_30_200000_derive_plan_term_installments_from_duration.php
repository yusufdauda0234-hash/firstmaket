<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan terms are defined by how long they run, not by a hand-typed payment
 * count.
 *
 * Before this, staff entered a name ("Weekly over 3 months") and a number of
 * payments as two unrelated fields, with nothing checking they agreed — which
 * is how a weekly-over-3-months term ended up with 13 payments. The duration is
 * now the input and the payment count is derived from it: 4 payments per month
 * weekly, 1 per month monthly.
 *
 * Safe for live plans: a running plan snapshots its own cadence, installment
 * count and amount, so recomputing a term never changes what a customer already
 * agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plan_terms', 'duration_months')) {
            Schema::table('plan_terms', function (Blueprint $table) {
                $table->unsignedSmallInteger('duration_months')->default(1)->after('cadence');
            });
        }

        // Backfill from whatever was typed in, then bring the payment count
        // back in line with the duration it implies.
        foreach (DB::table('plan_terms')->get() as $term) {
            $months = $term->cadence === 'weekly'
                ? max(1, (int) round($term->installments / 4))
                : max(1, (int) $term->installments);

            DB::table('plan_terms')->where('id', $term->id)->update([
                'duration_months' => $months,
                'installments' => $term->cadence === 'weekly' ? $months * 4 : $months,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plan_terms', 'duration_months')) {
            Schema::table('plan_terms', function (Blueprint $table) {
                $table->dropColumn('duration_months');
            });
        }
    }
};
