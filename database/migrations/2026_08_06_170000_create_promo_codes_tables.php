<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo codes, and a record of every redemption.
 *
 * Platform-funded only: the discount comes out of FirstMaket's commission and
 * the vendor's earning is untouched. That needs no vendor consent and keeps
 * the accounting honest — but it means a discount larger than the commission
 * on an order is FirstMaket paying a customer to shop, so the redemption
 * service caps it there.
 *
 * `promo_redemptions` is not a convenience: it drives the per-customer limit,
 * lets a refund release a use back to the customer, and is the only place
 * that can answer what a campaign actually cost.
 *
 * The apportioned discount is written onto each order because orders are one
 * row per unit — a refund or a vendor payout has to know this unit's share,
 * not the basket's total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            // Stored upper-case and matched that way, so "SAVE10" and
            // "save10" are the same code rather than two.
            $table->string('code', 32)->unique();
            $table->string('description', 200)->nullable();

            // percent | fixed | free_delivery
            $table->string('type', 20);
            $table->decimal('percent_off', 5, 2)->nullable();
            $table->unsignedBigInteger('amount_off_kobo')->nullable();
            // Required for percent codes: 20% off an unbounded basket is not
            // a promotion, it is an open cheque.
            $table->unsignedBigInteger('max_discount_kobo')->nullable();

            $table->unsignedBigInteger('min_order_kobo')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            // Null = unlimited.
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('max_per_customer')->default(1);
            $table->boolean('first_order_only')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('promo_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkout_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('discount_kobo');
            // Set when a refund or vendor rejection gives the use back, so a
            // customer is never charged a redemption for somebody else's
            // failure. Kept rather than deleted: what happened is history.
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            // One redemption per checkout, so a replayed request cannot spend
            // the code twice.
            $table->unique(['promo_code_id', 'checkout_session_id'], 'promo_redemption_once_per_checkout');
            $table->index(['promo_code_id', 'user_id', 'released_at']);
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable()->after('total_amount_kobo')
                ->constrained()->nullOnDelete();
            $table->unsignedBigInteger('promo_discount_kobo')->default(0)->after('promo_code_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            // This unit's share of the basket discount.
            $table->unsignedBigInteger('promo_discount_kobo')->default(0)->after('commission_amount_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('promo_discount_kobo');
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn('promo_discount_kobo');
        });

        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promo_codes');
    }
};
