<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 Cart and Multi-Vendor Checkout, part 2
 * (docs/FirstMaket-Database_Schema.md section 8a): the checkout side that
 * was deferred while the delivery-address-timing design question was open.
 * Resolution: address timing follows payment timing — a pay-in-full cart
 * checkout collects the address upfront on the checkout screen (before the
 * wallet is debited); a plan (single- or multi-product) still collects it
 * once fully funded, exactly like the existing Sprint 6 flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per full-payment cart checkout — groups the orders it
        // creates for "placed together" display and receipts. The address is
        // captured once here rather than per order.
        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('wallet_transaction_id')->constrained('wallet_transactions');
            $table->unsignedBigInteger('total_amount_kobo');
            $table->text('delivery_address');
            $table->string('state', 60);
            $table->string('lga', 80);
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
        });

        // Products bundled into a multi-product Product Target Plan. Absent
        // for single-product plans (product_target_plans.product_id is set
        // directly there instead).
        Schema::create('plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('product_target_plans')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('vendor_id')->constrained('vendor_profiles');
            // Locked at bundle creation, same as product_target_plans.target_price_kobo.
            $table->unsignedBigInteger('locked_price_kobo');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('created_at')->nullable();

            $table->index('plan_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Set when the order came from a cart full-payment checkout
            // instead of a plan (plan_id is null in that case).
            $table->foreignId('checkout_session_id')->nullable()->after('plan_id')
                ->constrained('checkout_sessions');
            // Set when the order came from a bundled multi-product plan,
            // pointing at the specific product's row in plan_items.
            $table->foreignId('plan_item_id')->nullable()->after('checkout_session_id')
                ->constrained('plan_items');
            // Shared by every order created together from one bundled plan's
            // funding completion, purely for grouped receipt/tracker display.
            $table->uuid('plan_delivery_group_id')->nullable()->after('plan_item_id');

            $table->index('checkout_session_id');
            $table->index('plan_delivery_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkout_session_id');
            $table->dropConstrainedForeignId('plan_item_id');
            $table->dropColumn('plan_delivery_group_id');
        });

        Schema::dropIfExists('plan_items');
        Schema::dropIfExists('checkout_sessions');
    }
};
