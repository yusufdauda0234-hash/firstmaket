<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('referral_code', 32)->unique();
            $table->string('status', 20)->default('pending');
            $table->foreignId('qualified_plan_id')->nullable()->constrained('savings_goals')->nullOnDelete();
            $table->unsignedBigInteger('reward_amount')->default(50_000);
            $table->timestamp('reward_credited_at')->nullable();
            $table->timestamps();

            $table->unique('referred_id');
            $table->index(['referrer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
