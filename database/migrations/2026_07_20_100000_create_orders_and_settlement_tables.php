<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 Orders, Logistics, and Vendor Settlement
 * (docs/firstmarket-Database_Schema.md section 9). Money is integer kobo.
 * Commission fields on orders are snapshots frozen at creation. The vendor
 * earnings ledger is append-only and fully separate from customer wallets
 * and savings — there is no path from it back to a customer balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('plan_id')->unique()->constrained('product_target_plans');
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('vendor_id')->constrained('vendor_profiles');
            $table->foreignId('product_id')->constrained();
            // Captured only after the plan is fully funded (Ready for Delivery).
            $table->text('delivery_address');
            $table->string('state', 60);
            $table->string('lga', 80);
            $table->string('status', 30)->default('pending');
            // Snapshots frozen at order creation — later changes never alter them.
            $table->unsignedBigInteger('locked_price_kobo');
            $table->decimal('commission_rate_percent', 5, 2);
            $table->unsignedBigInteger('commission_amount_kobo');
            $table->unsignedBigInteger('vendor_earning_amount_kobo');
            $table->timestamp('vendor_notified_at')->nullable();
            $table->timestamp('prepare_due_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('delivery_confirmed_at')->nullable();
            $table->timestamp('earnings_credited_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'prepare_due_at']);
            $table->index(['vendor_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('order_id');
        });

        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('logistics_user_id')->constrained('users');
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');
            $table->string('status', 20)->default('assigned');
            $table->timestamps();

            $table->index(['logistics_user_id', 'status']);
        });

        Schema::create('vendor_preparation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendor_profiles');
            $table->string('status', 30);
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'status']);
        });

        // Append-only rate history; the active rate is the latest effective_from in the past.
        Schema::create('category_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained();
            $table->decimal('rate_percent', 5, 2);
            $table->timestamp('effective_from');
            $table->foreignId('set_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['category_id', 'effective_from']);
        });

        Schema::create('vendor_earnings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained('vendor_profiles');
            $table->foreignId('order_id')->nullable()->constrained();
            $table->string('type', 20); // earning | adjustment | payout
            // Signed: positive for earnings, negative for payouts/adjustments.
            $table->bigInteger('amount_kobo');
            $table->bigInteger('balance_before_kobo');
            $table->bigInteger('balance_after_kobo');
            $table->foreignId('payout_item_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            // Earnings credit exactly once per delivered order.
            $table->unique(['order_id', 'type']);
            $table->index('vendor_id');
        });

        Schema::create('vendor_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendor_profiles');
            $table->string('bank_code', 20);
            $table->string('bank_name', 100)->nullable();
            $table->text('account_number'); // encrypted cast
            $table->string('account_name'); // resolved via Paystack
            $table->string('paystack_recipient_code')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['vendor_id', 'is_active']);
        });

        Schema::create('vendor_payout_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('total_amount_kobo')->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('vendor_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('vendor_payout_batches')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendor_profiles');
            $table->foreignId('bank_account_id')->constrained('vendor_bank_accounts');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 20)->default('pending');
            $table->string('paystack_transfer_reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payout_items');
        Schema::dropIfExists('vendor_payout_batches');
        Schema::dropIfExists('vendor_bank_accounts');
        Schema::dropIfExists('vendor_earnings');
        Schema::dropIfExists('category_commission_rates');
        Schema::dropIfExists('vendor_preparation_events');
        Schema::dropIfExists('delivery_assignments');
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('orders');
    }
};
