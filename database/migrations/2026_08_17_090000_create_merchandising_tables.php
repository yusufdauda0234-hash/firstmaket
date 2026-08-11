<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type')->default('flash');
            $table->text('description')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('campaign_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sale_price_kobo');
            $table->unsignedInteger('stock_cap')->nullable();
            $table->unsignedInteger('sold_quantity')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'product_id']);
            $table->index(['product_id', 'campaign_id']);
        });

        Schema::create('product_view_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('viewed_on');
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'viewed_on']);
        });

        Schema::create('search_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term')->unique();
            $table->unsignedInteger('search_count')->default(0);
            $table->timestamp('last_searched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_terms');
        Schema::dropIfExists('product_view_counts');
        Schema::dropIfExists('campaign_products');
        Schema::dropIfExists('campaigns');
    }
};