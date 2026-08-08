<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many instalments a customer may miss before the plan is let go.
 *
 * Until now only the *first* payment had a deadline. After that a plan could
 * sit untouched forever holding a price against inflation: `next_due_at` was
 * shown to the customer and advanced on every payment, but nothing anywhere
 * read it back.
 *
 * Set per term by an admin, then snapshotted onto the plan at signup for the
 * same reason the cadence and instalment count are — editing a term must
 * never change the deal a running plan was started on.
 *
 * Zero means never let go for inactivity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_terms', function (Blueprint $table) {
            $table->unsignedTinyInteger('missed_payments_allowed')->default(3)->after('first_payment_due_days');
        });

        Schema::table('savings_goals', function (Blueprint $table) {
            $table->unsignedTinyInteger('missed_payments_allowed')->nullable()->after('first_payment_due_at');
            // Stamped when the customer is warned, so the sweep warns once and
            // only revokes on a later pass — never both in the same run.
            $table->dateTime('dormancy_warned_at')->nullable()->after('missed_payments_allowed');

            $table->index(['status', 'next_due_at'], 'savings_goals_dormancy_idx');
        });
    }

    public function down(): void
    {
        Schema::table('plan_terms', function (Blueprint $table) {
            $table->dropColumn('missed_payments_allowed');
        });

        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropIndex('savings_goals_dormancy_idx');
            $table->dropColumn(['missed_payments_allowed', 'dormancy_warned_at']);
        });
    }
};
