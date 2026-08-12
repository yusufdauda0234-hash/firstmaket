<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's receipt for one checkout.
 *
 * One row per checkout session, not per order: a basket spanning three
 * vendors is three orders but one purchase, and a receipt that arrived in
 * triplicate for a single payment would be worse than none.
 *
 * Every figure is copied in rather than joined at render time. A receipt is a
 * record of what was charged on a date — if a price, a delivery rate or a
 * product name changes next month, the document must still say what the
 * customer actually paid.
 *
 * (The older `receipts` table belongs to the retired savings ledger and was
 * never written to; it is left untouched here rather than repurposed.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Human-facing document number: FM-2026-000123. What a customer
            // quotes to support and what accounting files against.
            $table->string('receipt_number', 32)->unique();

            // Unique: one checkout, one receipt. Also the idempotency guard —
            // a replayed payment webhook cannot issue a second document.
            $table->foreignId('checkout_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            $table->string('currency', 3)->default('NGN');
            $table->unsignedBigInteger('subtotal_kobo');
            $table->unsignedBigInteger('shipping_kobo')->default(0);
            $table->unsignedBigInteger('discount_kobo')->default(0);
            $table->unsignedBigInteger('total_kobo');
            // What was settled at checkout versus what the courier still
            // collects at the door. On a pay-on-delivery basket the two are
            // different numbers and a receipt that showed only the total
            // would read as "paid in full" when it is not.
            $table->unsignedBigInteger('paid_kobo')->default(0);
            $table->unsignedBigInteger('collect_on_delivery_kobo')->default(0);

            $table->string('payment_method', 40)->nullable();
            $table->string('payment_reference')->nullable();

            // Frozen lines and delivery details, exactly as charged.
            $table->json('items_snapshot');
            $table->json('billed_to');

            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_receipts');
    }
};
