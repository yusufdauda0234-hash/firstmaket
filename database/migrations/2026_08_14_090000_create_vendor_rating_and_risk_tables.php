<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D: vendor performance tiers, and risk flags for staff to review.
 *
 * Both are built the same way and for the same reason: every threshold lives
 * in a row an admin can edit, not in a constant. A marketplace's idea of "a
 * good vendor" or "suspicious" changes with the business, and it should not
 * take a deploy to say so.
 *
 * The tiers are computed, never accumulated. A vendor's score is a pure
 * function of facts already stored — delivered orders, rejections, returns,
 * product ratings — so running the calculation twice on the same data gives
 * the same answer, and a wrong threshold can be corrected by fixing the
 * threshold rather than by unpicking a running total.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Admin-defined tiers. Thresholds are all "at least this good".
        Schema::create('vendor_rating_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('colour', 20)->default('slate');

            // A vendor qualifies for the highest tier whose every condition
            // they meet. Nulls mean "this tier does not care about that".
            $table->unsignedSmallInteger('minimum_score')->default(0);
            $table->unsignedInteger('minimum_delivered_orders')->default(0);
            // Stored as percentages so the admin screen reads in the units
            // staff actually think in.
            $table->unsignedTinyInteger('maximum_rejection_percent')->nullable();
            $table->unsignedTinyInteger('maximum_return_percent')->nullable();

            $table->json('benefits');
            $table->boolean('status')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'minimum_score']);
        });

        /*
         * The current standing of each vendor, plus the numbers behind it.
         *
         * The inputs are stored alongside the result on purpose: a vendor
         * asking "why am I Silver" deserves the actual figures, and a support
         * agent should not have to re-derive them.
         */
        Schema::create('vendor_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained('vendor_profiles')->cascadeOnDelete();
            $table->foreignId('vendor_rating_tier_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedInteger('delivered_orders')->default(0);
            $table->unsignedInteger('rejected_orders')->default(0);
            $table->unsignedInteger('returned_orders')->default(0);
            $table->unsignedInteger('late_preparations')->default(0);
            $table->decimal('average_product_rating', 3, 2)->nullable();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index('vendor_rating_tier_id');
        });

        // History, so a tier change is visible rather than silently replacing
        // what came before.
        Schema::create('vendor_rating_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendor_profiles')->cascadeOnDelete();
            $table->foreignId('vendor_rating_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('score')->default(0);
            $table->json('metrics');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['vendor_id', 'captured_at']);
        });

        /*
         * Risk flags.
         *
         * Raised for a human to look at, and nothing more. Nothing in this
         * system suspends an account because a flag fired — the plan is
         * explicit about it and there is a test that holds the line. An
         * automated suspension on a heuristic is how a legitimate customer
         * loses access to money they have saved.
         */
        Schema::create('risk_flags', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // What the flag is about. Nullable because some rules are about a
            // vendor and some about a customer.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendor_profiles')->cascadeOnDelete();

            /*
             * Who the flag is about, as one non-null string ("user:12",
             * "vendor:4").
             *
             * The unique index below needs it: MySQL treats NULLs as distinct,
             * so a key over (rule, user_id, vendor_id, status) never fires for
             * a customer flag — vendor_id is null — and the daily sweep would
             * raise the same unreviewed condition again every night.
             */
            $table->string('subject_key', 40);

            $table->string('rule', 60);
            $table->string('severity', 20)->default('medium');
            $table->string('summary', 255);
            // The numbers that tripped it, so a reviewer sees the evidence
            // rather than being asked to trust the label.
            $table->json('evidence')->nullable();

            $table->string('status', 20)->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->string('outcome', 30)->nullable();

            $table->timestamps();

            // One open flag per rule per subject: the sweep runs daily and
            // must not pile up duplicates of a condition nobody has looked at.
            // Every column here is non-null, so the constraint actually holds.
            $table->unique(['rule', 'subject_key', 'status'], 'risk_flags_unique_open');
            $table->index(['status', 'severity', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_flags');
        Schema::dropIfExists('vendor_rating_snapshots');
        Schema::dropIfExists('vendor_ratings');
        Schema::dropIfExists('vendor_rating_tiers');
    }
};
