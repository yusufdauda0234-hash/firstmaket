<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reconciliation sweep runs every fifteen minutes and asks for pending
 * charges older than a cutoff, with no user in the predicate — so the
 * existing (user_id, status) index cannot serve it and MySQL falls back to
 * scanning a table that grows with every payment attempt ever made.
 *
 * This is the index that query actually needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'paystack_transactions_reconcile_idx');
        });
    }

    public function down(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->dropIndex('paystack_transactions_reconcile_idx');
        });
    }
};
