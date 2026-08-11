<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3C: the savings assistant, with a memory and a paper trail.
 *
 * The tables are shaped around one rule: the assistant may *propose*, and
 * only the customer may *accept*. A suggestion is a row; acting on it is a
 * different row, written only after the customer confirms. That separation
 * is what makes "the assistant cannot move money" a fact about the schema
 * rather than a promise about the code.
 *
 * Cost logs exist because an assistant backed by a paid model is a bill that
 * grows with usage, and a bill nobody is watching is how a feature quietly
 * becomes unaffordable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            // Scoped to one customer, always. There is no shared or global
            // conversation, so there is nowhere for one customer's history to
            // be read from another's session.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 20); // customer | assistant
            $table->text('body');
            // What the answer was worked out from, so a customer can check a
            // claim instead of taking it on trust.
            $table->json('evidence')->nullable();
            $table->string('driver', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id']);
        });

        // A suggestion the customer has not agreed to. Nothing acts on these.
        Schema::create('assistant_recommendations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('conversation_id')->nullable()->constrained('assistant_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_goal_id')->nullable()->constrained('savings_goals')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('title', 160);
            $table->string('body', 500);
            // The arguments the action would run with, frozen when it was
            // suggested — so what the customer confirms is exactly what they
            // were shown, not whatever the numbers say by the time they click.
            $table->json('payload')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('offered');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // The customer's answer, recorded separately and immutably.
        Schema::create('assistant_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->unique()->constrained('assistant_recommendations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 20); // accepted | declined
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('assistant_cost_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('assistant_conversations')->nullOnDelete();
            $table->string('driver', 40);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            // Kobo, so a spend cap is expressed in the same units as
            // everything else money-shaped in this codebase.
            $table->unsignedBigInteger('cost_kobo')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_cost_logs');
        Schema::dropIfExists('assistant_confirmations');
        Schema::dropIfExists('assistant_recommendations');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
