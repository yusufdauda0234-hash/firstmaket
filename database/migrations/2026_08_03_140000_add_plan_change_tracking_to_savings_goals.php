<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer has changed about their plan, and how many payments they
 * have actually made.
 *
 * `payments_made` replaces deriving the payment count from
 * paid_kobo / installment_kobo. That derivation assumes every instalment is
 * the same size, which stops being true the moment a plan is rescheduled or
 * switched: after spreading a remaining balance over a longer run, dividing
 * by the new smaller instalment reports payments that were never made.
 * Counting them is both simpler and true.
 *
 * `duration_months` is snapshotted for the same reason cadence and
 * instalments already are — an admin editing the term must not change what
 * counts as an extension for a plan already running.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_months')->nullable()->after('installments');
            $table->unsignedInteger('payments_made')->default(0)->after('installment_kobo');
            $table->unsignedTinyInteger('extension_count')->default(0)->after('payments_made');
            $table->unsignedTinyInteger('switch_count')->default(0)->after('extension_count');
        });

        // Existing plans: the payment rows are the record of what was paid.
        DB::statement('
            UPDATE savings_goals g
            SET payments_made = (
                SELECT COUNT(*) FROM plan_payments p WHERE p.savings_goal_id = g.id
            )
        ');

        // And their duration, from the term they were started on.
        DB::statement('
            UPDATE savings_goals g
            JOIN plan_terms t ON t.id = g.plan_term_id
            SET g.duration_months = t.duration_months
            WHERE g.plan_term_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropColumn(['duration_months', 'payments_made', 'extension_count', 'switch_count']);
        });
    }
};
