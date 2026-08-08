<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash on delivery, and the ledger that makes it survivable.
 *
 * Taking a smaller payment at checkout is the easy half. The hard half is
 * that ₦50,000 now exists in a courier's pocket and FirstMaket owes a vendor
 * out of it — so every note that changes hands gets a row here, and a
 * courier's balance is the sum of those rows rather than a number anybody
 * edits.
 *
 * Two entry types, deliberately not one signed column: a collection is money
 * arriving from a customer, a remittance is money going back to the office,
 * and they are confirmed by different people at different times. Netting them
 * into one figure would lose the only thing that matters when they disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('courier_user_id')->constrained('users');

            // collection | remittance
            $table->string('type', 20);
            $table->unsignedBigInteger('amount_kobo');

            // The doorstep this came from. Null on a remittance, which covers
            // many collections at once.
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();

            // A remittance is only real once somebody in the office says the
            // money arrived. Never the courier who handed it in — that is
            // enforced in the service, and this column is how it is audited.
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->index(['courier_user_id', 'type', 'confirmed_at']);
            // One collection per parcel: a delivered parcel must never be able
            // to bank the same cash twice.
            $table->unique(['shipment_id', 'type'], 'one_collection_per_parcel');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Null means the goods are still owed. Card and Pay Small Small
            // set it at creation; pay-on-delivery sets it at the door.
            $table->timestamp('goods_paid_at')->nullable()->after('promo_discount_kobo');
        });

        Schema::table('courier_profiles', function (Blueprint $table) {
            // The most cash this courier may be holding before they stop being
            // given pay-on-delivery work. Zero means no ceiling — which is a
            // choice, not a default, so the admin screen says so.
            $table->unsignedBigInteger('max_float_kobo')->default(0)->after('max_open_shipments');
        });

        Schema::table('shipments', function (Blueprint $table) {
            // What the courier must collect at the door, frozen when the
            // parcel is built. Zero on a prepaid order.
            $table->unsignedBigInteger('collect_on_delivery_kobo')->default(0)->after('delivery_code');
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            // Decided at checkout and carried onto the parcels, so what the
            // courier asks for is what the shopper agreed to — not something
            // recomputed later from prices that may have moved.
            $table->unsignedBigInteger('collect_on_delivery_kobo')->default(0)->after('promo_discount_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn('collect_on_delivery_kobo');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('collect_on_delivery_kobo');
        });

        Schema::table('courier_profiles', function (Blueprint $table) {
            $table->dropColumn('max_float_kobo');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('goods_paid_at');
        });

        Schema::dropIfExists('courier_cash_movements');
    }
};
