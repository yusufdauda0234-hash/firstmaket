<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Delivery pricing comes from this table and nowhere else.
 *
 * Two things used to be decided outside the admin screen. A blank
 * free-delivery threshold fell back to a figure in config/firstmaket.php,
 * and so did the fee itself when no rate matched — so a state priced at
 * ₦2,000 quietly charged nothing on any order over ₦15,000, and staff had no
 * way to see the number responsible.
 *
 * Now the threshold is never null: zero means never free, which is the
 * default, and free delivery only exists where somebody has deliberately set
 * a figure on the rates screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Clear the nulls FIRST: the column cannot be made NOT NULL while any
        // remain. Anything currently inheriting becomes "never free" rather
        // than silently keeping the config figure it was standing in for.
        DB::table('delivery_rates')->whereNull('free_threshold_kobo')->update(['free_threshold_kobo' => 0]);

        Schema::table('delivery_rates', function (Blueprint $table) {
            // Nullable meant "inherit", which is what hid the decision.
            $table->unsignedBigInteger('free_threshold_kobo')->default(0)->nullable(false)->change();
        });

        // The fallback every unpriced state uses has to exist, because there
        // is no longer a config figure behind it.
        //
        // Written through the query builder, not the model. A migration runs
        // against the schema as it was on the day it was written, but a model
        // is whatever it is today — and when the two leg columns were later
        // dropped from $fillable, an Eloquent create() here silently discarded
        // them and seeded a ₦0 default nationwide.
        if (! DB::table('delivery_rates')->whereNull('state')->exists()) {
            DB::table('delivery_rates')->insert([
                'uuid' => (string) Str::uuid(),
                'state' => null,
                'vendor_to_hub_kobo' => 50_000,
                'hub_to_customer_kobo' => 100_000,
                'free_threshold_kobo' => 0,
                'is_active' => true,
                'note' => 'Created automatically — every state without its own rate uses this.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('delivery_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('free_threshold_kobo')->nullable()->change();
        });
    }
};
