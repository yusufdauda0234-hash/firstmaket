<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->unsignedBigInteger('minimum_completed_savings')->default(0);
            $table->json('benefits');
            $table->boolean('status')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'minimum_completed_savings']);
        });

        Schema::create('user_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('reward_tier_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lifetime_completed_savings')->default(0);
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();

            $table->index('reward_tier_id');
        });

        $now = now();
        DB::table('reward_tiers')->insert([
            // A JSON list, never an object: the admin screen edits benefits as
            // one line of text per benefit, so a keyed shape here would reach
            // the page as an object and break it.
            [
                'name' => 'Bronze',
                'minimum_completed_savings' => 0,
                'benefits' => json_encode(['A steady start']),
                'status' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Silver',
                'minimum_completed_savings' => 1_000_000,
                'benefits' => json_encode(['Reliable saver']),
                'status' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Gold',
                'minimum_completed_savings' => 5_000_000,
                'benefits' => json_encode(['Committed planner']),
                'status' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Platinum Saver',
                'minimum_completed_savings' => 20_000_000,
                'benefits' => json_encode(['Savings champion']),
                'status' => true,
                'sort_order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_rewards');
        Schema::dropIfExists('reward_tiers');
    }
};
