<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            /*
             * No ->after('collect_on_delivery_kobo') here.
             *
             * That column is created by 2026_08_09_090000, which runs after
             * this one, so on a fresh database this failed with "Unknown
             * column 'collect_on_delivery_kobo'". It only ever worked where
             * the migrations happened to be applied out of order. Column
             * position is cosmetic; the dependency was not.
             */
            // cash | customer_online | courier_online
            $table->string('goods_collection_method', 30)->default('cash');
            $table->timestamp('goods_paid_at')->nullable()->after('goods_collection_method');
            $table->foreignId('goods_paid_by')->nullable()->after('goods_paid_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('checkout_session_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_paid_by');
            $table->dropColumn(['goods_collection_method', 'goods_paid_at']);
        });
    }
};
