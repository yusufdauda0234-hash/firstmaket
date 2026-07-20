<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Vendor-claimed usual market price, in kobo — powers the
            // strikethrough compare-at display. Never used for billing.
            $table->unsignedBigInteger('compare_at_price_kobo')->nullable()->after('price_kobo');
            // Denormalized rating summary; recomputed once the reviews
            // module lands. Null average = "no ratings yet".
            $table->decimal('rating_average', 2, 1)->nullable()->after('stock_quantity');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_average');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['compare_at_price_kobo', 'rating_average', 'rating_count']);
        });
    }
};
