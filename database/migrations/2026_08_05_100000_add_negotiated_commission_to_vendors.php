<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A commission rate negotiated with one vendor, and a record of which rule
 * decided the rate on each order.
 *
 * Commission resolved category → platform default, with no way to agree a
 * rate with a large seller short of moving their whole catalogue into its own
 * category. A nullable rate on the vendor sits in front of that: null means
 * "no special deal", which is every vendor until somebody negotiates one.
 *
 * `orders.commission_source` records which rule won, snapshotted with the
 * rate itself. Re-deriving it later would answer with today's rules, not the
 * ones the order was actually priced under — which is the same reason the
 * rate and the vendor's earning are snapshotted rather than recomputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_profiles', function (Blueprint $table) {
            $table->decimal('commission_rate_percent', 5, 2)->nullable()->after('status');
            // Why the deal exists, for whoever inherits the account.
            $table->string('commission_note', 200)->nullable()->after('commission_rate_percent');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('commission_source', 20)
                ->default('default')
                ->after('commission_rate_percent');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_profiles', function (Blueprint $table) {
            $table->dropColumn(['commission_rate_percent', 'commission_note']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('commission_source');
        });
    }
};
