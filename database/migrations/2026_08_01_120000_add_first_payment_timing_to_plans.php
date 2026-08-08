<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the first instalment on a Pay Small Small plan falls due.
 *
 * Admins set this per term. Zero days means the customer pays on the spot at
 * checkout — the plan is not started until that payment lands. Anything
 * higher gives them that many days to make the first payment; miss it and
 * the plan is revoked and whatever was paid becomes credit.
 *
 * The deadline is stamped onto the plan at creation rather than read back
 * off the term, so editing or retiring a term never moves the goalposts on a
 * plan already running — the same reason cadence and instalments are
 * snapshotted there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_terms', function (Blueprint $table) {
            $table->unsignedSmallInteger('first_payment_due_days')->default(0)->after('min_target_kobo');
        });

        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dateTime('first_payment_due_at')->nullable()->after('next_due_at');
            $table->index(['status', 'first_payment_due_at'], 'savings_goals_first_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('plan_terms', function (Blueprint $table) {
            $table->dropColumn('first_payment_due_days');
        });

        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropIndex('savings_goals_first_payment_idx');
            $table->dropColumn('first_payment_due_at');
        });
    }
};
