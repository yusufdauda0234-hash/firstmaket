<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B: scheduled automatic debit for Pay Small Small instalments.
 *
 * One row per plan — a plan is the only thing money can be paid into, so an
 * automatic debit belongs to a plan rather than to a customer. The card is
 * referenced, never copied: `payment_authorization_id` points at the reusable
 * authorization Paystack already gave us, and no card number, CVV or PAN is
 * stored anywhere in this system.
 *
 * Failure handling lives here rather than in a job queue because the rule is
 * about calendar time, not retries-in-flight: a failed charge is tried once
 * more a day later, and if that fails too the debit stops and asks for the
 * card again (`needs_reauthorization`). Nothing retries in a tight loop
 * against someone's bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automatic_debits', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The plan being paid into. Unique: two debits against one plan
            // would double-charge on every run.
            $table->foreignId('savings_goal_id')
                ->unique()
                ->constrained('savings_goals')
                ->cascadeOnDelete();

            // Null once a card is removed — the debit then needs a new one
            // rather than silently charging a card the customer revoked.
            $table->foreignId('payment_authorization_id')
                ->nullable()
                ->constrained('payment_authorizations')
                ->nullOnDelete();

            $table->unsignedBigInteger('amount_kobo');

            // active | needs_reauthorization | cancelled
            $table->string('status', 30)->default('active');

            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();

            // Consecutive failures. Reset by any success; at 2 the debit stops
            // and waits for a fresh card.
            $table->unsignedTinyInteger('failure_count')->default(0);
            $table->string('last_error', 255)->nullable();

            $table->timestamps();

            // The scheduler's only query: everything active and due.
            $table->index(['status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automatic_debits');
    }
};
