<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5 Purchase and Savings Engine (docs/firstmarket-Database_Schema.md
 * section 8). Pay At Once is modeled as product_target_plans.payment_mode =
 * 'pay_at_once' (the schema doc's stated option), so no direct_checkouts
 * table. Money amounts are integer kobo; balances are unsigned so they can
 * never go negative at the database level. No withdrawal path exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One Open Savings pot per customer, funded only from the wallet.
        Schema::create('open_savings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance_kobo')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('product_target_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            // Copied from the product at creation and never automatically changed.
            $table->unsignedBigInteger('target_price_kobo');
            $table->string('payment_mode', 20); // schedule | pay_at_once
            $table->string('cadence', 20)->nullable(); // daily | weekly | monthly (schedule mode only)
            $table->unsignedBigInteger('suggested_contribution_kobo')->nullable();
            $table->unsignedBigInteger('amount_saved_kobo')->default(0);
            $table->unsignedBigInteger('remaining_balance_kobo');
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->date('expected_completion_date')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_contribution_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->timestamp('ready_for_delivery_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });

        Schema::create('plan_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('product_target_plans')->cascadeOnDelete();
            // Null when money came from Open Savings/redirection (wallet balance untouched).
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions');
            $table->unsignedBigInteger('amount_kobo');
            $table->date('contribution_date');
            $table->string('source', 30); // paystack_deposit | open_savings | redirection
            $table->timestamps();

            $table->index(['plan_id', 'contribution_date']);
        });

        // Every redirection is recorded here and audit-logged — money is never
        // refunded as cash, only moved between savings targets.
        Schema::create('plan_redirections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 20); // open_savings | plan
            $table->unsignedBigInteger('source_id');
            $table->foreignId('target_plan_id')->constrained('product_target_plans');
            $table->foreignId('old_product_id')->nullable()->constrained('products');
            $table->foreignId('new_product_id')->constrained('products');
            $table->unsignedBigInteger('balance_transferred_kobo');
            $table->unsignedBigInteger('old_target_price_kobo')->nullable();
            $table->unsignedBigInteger('new_target_price_kobo');
            $table->timestamp('created_at')->nullable();

            $table->index(['source_type', 'source_id']);
        });

        Schema::create('plan_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('product_target_plans')->cascadeOnDelete();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_status_events');
        Schema::dropIfExists('plan_redirections');
        Schema::dropIfExists('plan_contributions');
        Schema::dropIfExists('product_target_plans');
        Schema::dropIfExists('open_savings');
    }
};
