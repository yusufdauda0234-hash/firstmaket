<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the affiliate tier ladder into a rank progression an admin controls.
 *
 * The tiers were previously earned silently: hit a threshold and the rate
 * changed. That works as a reward but not as a gate — there was no point at
 * which anybody looked at who they were paying, and no way to ask a partner
 * for a CAC document before letting them recruit at scale.
 *
 * A rank now carries a referral quota. Once it is used up the partner stops
 * earning new commission and must apply to the next rank, submitting whatever
 * that rank asks for. Their links keep working throughout — a customer who
 * clicks one has done nothing wrong and should not hit a dead end.
 *
 * Everything about the ladder is data: the ranks, their quotas, their link
 * expiry, and the requirements each one asks for. Adding a rank is an admin
 * screen, not a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_tiers', function (Blueprint $table) {
            /*
             * How many referrals this rank allows before the partner has to
             * upgrade. Zero means unlimited, which is what the top rank
             * carries — a ladder that never ends is not a ladder.
             */
            $table->unsignedInteger('referral_quota')->default(0)->after('vendor_recruitment_kobo');

            /*
             * How long a link created at this rank stays live. Zero means it
             * never expires. Short-lived links at the bottom of the ladder
             * limit the damage a partner can do before anybody has verified
             * who they are.
             */
            $table->unsignedInteger('link_expiry_days')->default(0)->after('referral_quota');

            /** How many links may be active at once. Zero means unlimited. */
            $table->unsignedInteger('max_active_links')->default(0)->after('link_expiry_days');

            /*
             * Whether reaching this rank needs a human to say yes. The first
             * rank is entered automatically on approval; higher ones ask for
             * documents somebody has to actually look at.
             */
            $table->boolean('requires_approval')->default(true)->after('max_active_links');
        });

        /*
         * What a rank asks for before somebody may enter it.
         *
         * A row per question rather than a fixed set of columns, because the
         * whole point is that an admin can add "NIN" or "two references" next
         * year without a migration.
         */
        Schema::create('affiliate_rank_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tier_id')->constrained('affiliate_tiers')->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('help_text', 255)->nullable();
            // document | text | number
            $table->string('type', 20)->default('text');
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tier_id', 'sort_order']);
        });

        Schema::create('affiliate_upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_tier_id')->nullable()->constrained('affiliate_tiers')->nullOnDelete();
            $table->foreignId('to_tier_id')->constrained('affiliate_tiers')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
            $table->index('status');
        });

        /** One answer per requirement on a request. */
        Schema::create('affiliate_upgrade_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('affiliate_upgrade_requests')->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('affiliate_rank_requirements')->cascadeOnDelete();
            $table->text('value')->nullable();
            // Documents go to the private disk, exactly like vendor CAC files.
            $table->foreignId('uploaded_document_id')->nullable()
                ->constrained('uploaded_documents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['request_id', 'requirement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_upgrade_answers');
        Schema::dropIfExists('affiliate_upgrade_requests');
        Schema::dropIfExists('affiliate_rank_requirements');

        Schema::table('affiliate_tiers', function (Blueprint $table) {
            $table->dropColumn([
                'referral_quota',
                'link_expiry_days',
                'max_active_links',
                'requires_approval',
            ]);
        });
    }
};
