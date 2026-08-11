<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3A: turns the single-link affiliate scheme into a partner program —
 * named campaign links, an attribution window, tiered commission rules,
 * verified payout destinations, Finance-approved payout batches, and fraud
 * flags.
 *
 * New tables are created before the ALTERs that reference them, so the whole
 * migration applies in one pass on a fresh database.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Commission rules ────────────────────────────────────────────────
        // A tier is a named rule set, not a hardcoded percentage. `min_*`
        // thresholds are what an affiliate must have reached to sit in it;
        // the highest qualifying tier wins.
        Schema::create('affiliate_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description')->nullable();
            // 'percent' takes a cut of the order; 'flat' pays a fixed amount
            // per conversion regardless of basket size.
            $table->string('commission_type', 20)->default('percent');
            $table->decimal('commission_percent', 5, 2)->default(5);
            $table->unsignedBigInteger('flat_amount_kobo')->default(0);
            // Vendor recruitment is paid differently from a shopper's order —
            // signing up a seller is worth more than one delivered basket.
            $table->unsignedBigInteger('vendor_recruitment_kobo')->default(0);
            $table->unsignedInteger('min_delivered_conversions')->default(0);
            $table->unsignedBigInteger('min_delivered_value_kobo')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // ── Payout destination ──────────────────────────────────────────────
        Schema::create('affiliate_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 100);
            $table->string('bank_code', 10)->nullable();
            // Encrypted at rest for the same reason vendor bank details are:
            // a leaked dump must not hand out payout destinations.
            $table->text('account_number');
            $table->string('account_name', 120);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['affiliate_id', 'is_active']);
        });

        // ── Payout batches ──────────────────────────────────────────────────
        Schema::create('affiliate_payout_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 30)->default('pending_approval');
            $table->unsignedBigInteger('total_amount_kobo')->default(0);
            $table->unsignedBigInteger('minimum_threshold_kobo')->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('affiliate_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('affiliate_payout_batches')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('affiliate_bank_accounts')->nullOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('paystack_transfer_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'affiliate_id']);
            $table->index(['affiliate_id', 'status']);
        });

        // ── Fraud ───────────────────────────────────────────────────────────
        Schema::create('affiliate_fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversion_id')->nullable()->constrained('affiliate_conversions')->cascadeOnDelete();
            $table->string('reason', 60);
            $table->string('detail')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
        });

        // ── Extensions to the existing tables ───────────────────────────────
        Schema::table('affiliate_links', function (Blueprint $table) {
            // Signed links: the code identifies the link, the signature proves
            // the URL was not hand-edited. Nullable so links created before
            // this migration keep working unsigned.
            $table->string('signature', 64)->nullable()->after('code');
            $table->string('campaign', 80)->nullable()->after('label');
            $table->timestamp('expires_at')->nullable()->after('status');
        });

        Schema::table('affiliate_attributions', function (Blueprint $table) {
            // The window is stamped on the attribution rather than read from
            // settings at qualification time: shortening the window later must
            // not retroactively void attributions partners already earned.
            $table->timestamp('expires_at')->nullable()->after('token');
            $table->index('expires_at');
        });

        Schema::table('affiliates', function (Blueprint $table) {
            $table->foreignId('tier_id')->nullable()->after('status')->constrained('affiliate_tiers')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable()->after('approved_at');
            $table->string('suspension_reason')->nullable()->after('suspended_at');
        });

        Schema::table('affiliate_commissions', function (Blueprint $table) {
            $table->foreignId('payout_item_id')->nullable()->after('status')
                ->constrained('affiliate_payout_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_item_id');
        });

        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tier_id');
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });

        Schema::table('affiliate_attributions', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });

        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->dropColumn(['signature', 'campaign', 'expires_at']);
        });

        Schema::dropIfExists('affiliate_fraud_flags');
        Schema::dropIfExists('affiliate_payout_items');
        Schema::dropIfExists('affiliate_payout_batches');
        Schema::dropIfExists('affiliate_bank_accounts');
        Schema::dropIfExists('affiliate_tiers');
    }
};
