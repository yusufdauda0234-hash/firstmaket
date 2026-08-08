<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What delivery costs, per state, split into the two legs FirstMaket
 * actually pays for: vendor → hub (the pickup we run), and hub → customer
 * (the drop-off). The customer is quoted the sum; the split is what makes
 * the number defensible internally and lets one leg be repriced without
 * guessing at the other.
 *
 * A null state is the fallback rate every state without its own row uses,
 * so the table is never required to hold all 37 rows to be correct.
 *
 * This replaces a flat fee that lived in config/env and could only be
 * changed by editing a file and redeploying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            // Null = the default rate, used by any state without its own row.
            $table->string('state', 60)->nullable()->unique();
            $table->unsignedInteger('vendor_to_hub_kobo')->default(0);
            $table->unsignedInteger('hub_to_customer_kobo')->default(0);
            // Orders at or above this are delivered free. Null falls back to
            // the default row's threshold.
            $table->unsignedBigInteger('free_threshold_kobo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note', 200)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_rates');
    }
};
