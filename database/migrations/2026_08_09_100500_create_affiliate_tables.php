<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 120);
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->string('label', 80)->default('Main link');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['affiliate_id', 'status']);
        });

        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_link_id')->constrained()->cascadeOnDelete();
            $table->string('ip_hash', 64);
            $table->string('fingerprint_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['affiliate_link_id', 'fingerprint_hash', 'created_at'], 'affiliate_click_dedupe_idx');
        });

        Schema::create('affiliate_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamps();
        });

        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_attribution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->nullable()->constrained()->nullOnDelete();
            $table->string('conversion_type', 30);
            $table->string('status', 20)->default('qualified');
            $table->unsignedBigInteger('order_value_kobo')->default(0);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamps();
            $table->index(['affiliate_id', 'status']);
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversion_id')->unique()->constrained('affiliate_conversions')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['affiliate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_conversions');
        Schema::dropIfExists('affiliate_attributions');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliate_links');
        Schema::dropIfExists('affiliates');
    }
};
