<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4: wallet and Paystack (docs/firstmarket-Database_Schema.md section 7).
 * Money is integer kobo throughout — never floats. The wallet is deposit-only:
 * there is no withdrawal table, column, or path anywhere. The ledger
 * (wallet_transactions) is immutable and only ever credited by a verified
 * Paystack webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->default('NGN');
            // Deposit-only: an unsigned column makes a negative balance a
            // database-level impossibility, not just an application rule.
            $table->unsignedBigInteger('balance_kobo')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');       // deposit, plan_contribution, ...
            $table->string('direction');  // credit, debit
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('balance_before_kobo');
            $table->unsignedBigInteger('balance_after_kobo');
            // Unique per money movement — the Paystack reference for deposits.
            // This is the idempotency guard: a duplicate webhook cannot create
            // a second ledger row for the same reference.
            $table->string('reference')->unique();
            $table->string('receipt_number')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('paystack_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('paystack_reference')->unique();
            $table->string('access_code')->nullable();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 3)->default('NGN');
            $table->string('channel')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('webhook_verified_at')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Raw webhook event log for replay protection, idempotency and debugging.
        Schema::create('paystack_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event')->nullable();
            $table->string('paystack_reference')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('payload_hash', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_status')->default('received'); // received, processed, ignored, failed
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('paystack_reference');
            $table->index('payload_hash');
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number')->unique();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 3)->default('NGN');
            $table->string('channel')->nullable();
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('emailed_at')->nullable();
            $table->string('pdf_path')->nullable();
        });

        // Reusable card metadata for Phase 2 scheduled automatic debit. No
        // charging happens in Sprint 4 — we only store what Paystack returns.
        Schema::create('payment_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('authorization_code');
            $table->string('signature')->nullable();
            $table->string('card_type')->nullable();
            $table->string('bank')->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('exp_month', 2)->nullable();
            $table->string('exp_year', 4)->nullable();
            $table->boolean('reusable')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'authorization_code']);
        });

        Schema::create('settlement_imports', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('paystack');
            $table->string('file_path')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending, completed, failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('settlement_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_import_id')->constrained()->cascadeOnDelete();
            $table->string('paystack_reference');
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('provider_amount_kobo')->nullable();
            $table->unsignedBigInteger('ledger_amount_kobo')->nullable();
            $table->string('status'); // matched, missing_in_ledger, missing_in_provider, amount_mismatch
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['settlement_import_id', 'status'], 'settlement_recon_import_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_reconciliation_items');
        Schema::dropIfExists('settlement_imports');
        Schema::dropIfExists('payment_authorizations');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('paystack_webhook_events');
        Schema::dropIfExists('paystack_transactions');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
