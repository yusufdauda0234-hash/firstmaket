<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Paystack charge used to mean exactly one thing — top up the balance —
 * so the webhook had nothing to decide. With the balance gone a charge now
 * pays for one of two things, and the webhook has to know which before it
 * can act on the money.
 *
 * The target is recorded when the charge is initialized, not read back out
 * of Paystack metadata, so a tampered callback cannot redirect someone
 * else's payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            // order | plan_installment
            $table->string('purpose', 30)->default('order')->after('user_id');
            $table->foreignId('checkout_session_id')->nullable()->after('purpose')->constrained()->nullOnDelete();
            $table->foreignId('savings_goal_id')->nullable()->after('checkout_session_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkout_session_id');
            $table->dropConstrainedForeignId('savings_goal_id');
            $table->dropColumn('purpose');
        });
    }
};
