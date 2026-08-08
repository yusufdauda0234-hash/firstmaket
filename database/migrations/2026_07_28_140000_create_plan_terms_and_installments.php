<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay Small Small becomes a real installment plan.
 *
 * Customers no longer hold a balance. Money enters the system attached to
 * one plan and one product, paid in fixed installments until the locked
 * price is covered — which is what a layaway actually is, and what shoppers
 * who cannot pay today are asking for.
 *
 * The available terms are admin-controlled (plan_terms), not hardcoded:
 * FirstMaket decides that, say, weekly-over-12 and monthly-over-6 are
 * offered, and the customer picks one at checkout. The installment amount is
 * then simply the locked price divided by that term's installment count.
 *
 * `credit_kobo` on savings is what is left when a plan is cancelled — it can
 * only ever be applied to another plan, never withdrawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_terms', function (Blueprint $table) {
            $table->id();
            // Shown to the customer, e.g. "Weekly over 3 months".
            $table->string('name', 80);
            // weekly | monthly
            $table->string('cadence', 20);
            $table->unsignedSmallInteger('installments');
            // A floor stops a term producing derisory payments on cheap items.
            $table->unsignedBigInteger('min_target_kobo')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cadence', 'installments']);
        });

        Schema::table('savings_goals', function (Blueprint $table) {
            $table->foreignId('plan_term_id')->nullable()->after('target_kobo')->constrained()->nullOnDelete();
            // Snapshotted from the term, so an admin editing it later cannot
            // move the goalposts on a plan already running.
            $table->string('cadence', 20)->nullable()->after('plan_term_id');
            $table->unsignedSmallInteger('installments')->default(1)->after('cadence');
            $table->unsignedBigInteger('installment_kobo')->default(0)->after('installments');
            // Running total, so progress never depends on a separate balance.
            $table->unsignedBigInteger('paid_kobo')->default(0)->after('installment_kobo');
            $table->timestamp('next_due_at')->nullable()->after('paid_kobo');
            $table->timestamp('started_at')->nullable()->after('next_due_at');
        });

        Schema::create('plan_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('paid_before_kobo');
            $table->unsignedBigInteger('paid_after_kobo');
            // card (Paystack) | credit (carried from a cancelled plan)
            $table->string('source', 20)->default('card');
            $table->string('reference')->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['savings_goal_id', 'created_at']);
        });

        Schema::table('savings', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_kobo')->default(0)->after('balance_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('savings', fn (Blueprint $table) => $table->dropColumn('credit_kobo'));

        Schema::dropIfExists('plan_payments');

        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_term_id');
            $table->dropColumn([
                'cadence', 'installments', 'installment_kobo', 'paid_kobo', 'next_due_at', 'started_at',
            ]);
        });

        Schema::dropIfExists('plan_terms');
    }
};
