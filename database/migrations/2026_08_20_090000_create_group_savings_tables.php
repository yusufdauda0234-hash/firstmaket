<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B: saving with other people, without inventing a wallet.
 *
 * The rule the whole phase turns on is that FirstMaket has no pooled
 * balance anybody can draw on — money enters against one specific plan and
 * can only ever become those goods. Each of the three models below keeps
 * that true a different way:
 *
 *  - A **group purchase** funds one plan owned by one organiser. Everyone's
 *    contribution is written down against their own name, so "who paid what"
 *    survives the group.
 *  - A **family group** moves no money at all. It is a read-only summary of
 *    members' own separate plans, shown only to members who opted in.
 *  - A **cooperative** rotates whose plan gets funded each cycle. The
 *    "payout" is somebody's plan advancing, never cash — which is what makes
 *    a rotating scheme expressible here at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Group purchase ──────────────────────────────────────────────────
        Schema::create('group_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            // The single plan every contribution lands on. Deleting the plan
            // takes the group with it — a group without its plan is a shell
            // that would imply money exists somewhere it does not.
            $table->foreignId('savings_goal_id')->constrained('savings_goals')->cascadeOnDelete();
            $table->foreignId('organiser_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('open');
            // Short shareable code. Joining still needs the member to accept,
            // so a leaked code cannot add somebody silently.
            $table->string('invite_code', 12)->unique();
            $table->timestamps();

            $table->index(['organiser_id', 'status']);
        });

        Schema::create('group_plan_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->string('status', 20)->default('invited');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->timestamps();

            $table->unique(['group_plan_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        // The ownership ledger. One row per contribution, naming the member
        // who made it and the plan payment it became — so a share can always
        // be traced back to real money that really arrived.
        Schema::create('group_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_payment_id')->unique()->constrained('plan_payments')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['group_plan_id', 'user_id']);
        });

        // ── Family group ────────────────────────────────────────────────────
        Schema::create('family_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('invite_code', 12)->unique();
            $table->timestamps();
        });

        Schema::create('family_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('invited');
            // Consent is explicit and revocable: a family member shares a
            // summary of their plans only while this is true.
            $table->boolean('shares_progress')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['family_group_id', 'user_id']);
        });

        // ── Cooperative (ajo/esusu) ─────────────────────────────────────────
        Schema::create('cooperative_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organiser_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('contribution_kobo');
            $table->string('cadence', 20)->default('monthly');
            $table->string('status', 20)->default('forming');
            $table->string('invite_code', 12)->unique();
            $table->timestamps();

            $table->index(['organiser_id', 'status']);
        });

        Schema::create('cooperative_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Where they sit in the rotation. Fixed when the group starts, so
            // nobody's turn can be moved after contributions begin.
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 20)->default('invited');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['cooperative_group_id', 'user_id']);
            $table->unique(['cooperative_group_id', 'position']);
        });

        Schema::create('cooperative_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle_number');
            $table->foreignId('beneficiary_user_id')->constrained('users')->cascadeOnDelete();
            // Whose plan this cycle is funding. The cycle cannot open without
            // one: there has to be somewhere for the money to land that is
            // not a balance.
            $table->foreignId('beneficiary_goal_id')->nullable()->constrained('savings_goals')->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['cooperative_group_id', 'cycle_number']);
        });

        Schema::create('cooperative_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_payment_id')->unique()->constrained('plan_payments')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['cooperative_cycle_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_contributions');
        Schema::dropIfExists('cooperative_cycles');
        Schema::dropIfExists('cooperative_members');
        Schema::dropIfExists('cooperative_groups');
        Schema::dropIfExists('family_group_members');
        Schema::dropIfExists('family_groups');
        Schema::dropIfExists('group_contributions');
        Schema::dropIfExists('group_plan_members');
        Schema::dropIfExists('group_plans');
    }
};
