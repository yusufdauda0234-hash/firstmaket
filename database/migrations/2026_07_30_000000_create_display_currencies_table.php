<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies the storefront can *display* prices in.
 *
 * Prices stay stored as integer kobo and every charge is settled in NGN
 * through Paystack — this table only drives what a shopper sees while
 * browsing. Rates therefore live in the database rather than a config
 * constant: a hardcoded rate silently rots and starts quoting shoppers a
 * price that no longer holds, so staff need to be able to correct it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('display_currencies')) {
            return;
        }

        Schema::create('display_currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();          // ISO 4217
            $table->string('symbol', 8);
            $table->string('name');

            // How many units of this currency one naira buys. NGN itself is
            // 1.0. Stored with wide precision because rates for currencies
            // like USD are small fractions of a naira.
            $table->decimal('units_per_naira', 18, 10);

            // 0 for currencies that are not conventionally shown with minor
            // units at these amounts (NGN, XOF, JPY).
            $table->unsignedTinyInteger('decimals')->default(2);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('display_currencies');
    }
};
