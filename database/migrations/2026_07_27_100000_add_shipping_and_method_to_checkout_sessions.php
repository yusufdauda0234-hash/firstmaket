<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart checkout gained a real delivery fee and a chosen payment method, so
 * both have to be recorded alongside the total that was actually debited —
 * otherwise a receipt cannot explain why the charge exceeded the goods.
 * total_amount_kobo stays the full amount charged, shipping included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('shipping_fee_kobo')->default(0)->after('total_amount_kobo');
            $table->string('payment_method', 30)->default('wallet')->after('shipping_fee_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn(['shipping_fee_kobo', 'payment_method']);
        });
    }
};
