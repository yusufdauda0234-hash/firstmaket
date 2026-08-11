<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('threshold_percent')->default(10);
            $table->unsignedBigInteger('last_notified_price_kobo')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
            $table->index(['product_id', 'threshold_percent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_price_alerts');
    }
};