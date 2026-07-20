<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3: catalog and vendor listing
 * (docs/firstmarket-Database_Schema.md section 6). Money is integer kobo
 * throughout — never floats (docs/firstmarket_Implementation_Plan.md
 * "Key Engineering Rules").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained('vendor_profiles')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            // Vendor-controlled price in kobo; admins can never edit it.
            $table->unsignedBigInteger('price_kobo');
            $table->unsignedInteger('stock_quantity')->default(1);
            $table->string('status')->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('delisted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category_id', 'price_kobo']);
            $table->index(['vendor_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('product_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('old_price_kobo');
            $table->unsignedBigInteger('new_price_kobo');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
        });

        Schema::create('vendor_fee_settings', function (Blueprint $table) {
            $table->id();
            // 'free' or 'paid' — when paid, tier fees below apply to new posts.
            $table->string('posting_mode')->default('free');
            $table->unsignedBigInteger('basic_fee_kobo')->default(50000);
            $table->unsignedBigInteger('premium_fee_kobo')->default(200000);
            $table->unsignedBigInteger('featured_fee_kobo')->default(500000);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_posting_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('tier')->default('free');
            $table->unsignedBigInteger('amount_kobo')->default(0);
            // not_required (free mode), pending, paid.
            $table->string('payment_status')->default('not_required');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id']);
        });

        Schema::create('ai_listing_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // pending, passed, flagged, failed — advisory only; humans decide
            // (Sprint 8 fills this in; the structure ships with Sprint 3).
            $table->string('status')->default('pending');
            $table->json('flags')->nullable();
            $table->text('summary')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_listing_reviews');
        Schema::dropIfExists('product_posting_fees');
        Schema::dropIfExists('vendor_fee_settings');
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('product_status_events');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
