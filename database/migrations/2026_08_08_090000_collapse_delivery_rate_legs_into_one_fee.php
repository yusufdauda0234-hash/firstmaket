<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One delivery fee, priced on where the customer is.
 *
 * The rate used to be kept as two legs — collecting from the vendor, then
 * delivering to the door — on the reasoning that it is how the cost is
 * incurred. In practice nobody prices it that way here: the customer is
 * quoted the sum, both legs are always edited together, and the split was two
 * boxes to fill in for one number that mattered.
 *
 * Backfilled as the sum, so no rate changes value: a state charging ₦500 +
 * ₦1,000 keeps charging ₦1,500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('fee_kobo')->default(0)->after('state');
        });

        // The sum is what the customer was already being quoted, so this
        // changes no price anywhere.
        DB::statement('UPDATE delivery_rates SET fee_kobo = vendor_to_hub_kobo + hub_to_customer_kobo');

        Schema::table('delivery_rates', function (Blueprint $table) {
            $table->dropColumn(['vendor_to_hub_kobo', 'hub_to_customer_kobo']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_to_hub_kobo')->default(0)->after('state');
            $table->unsignedBigInteger('hub_to_customer_kobo')->default(0)->after('vendor_to_hub_kobo');
        });

        // The split is not recoverable — it was never stored separately once
        // collapsed — so the whole fee goes on the last leg, which is the one
        // that survives a partial rollback most usefully.
        DB::statement('UPDATE delivery_rates SET hub_to_customer_kobo = fee_kobo');

        Schema::table('delivery_rates', function (Blueprint $table) {
            $table->dropColumn('fee_kobo');
        });
    }
};
