<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With the balance gone, a card checkout can no longer be settled inside the
 * request that starts it: the customer leaves for Paystack and only the
 * verified webhook can say the money arrived.
 *
 * So a session is now created `pending` with the cart and address already
 * frozen onto it, and the webhook flips it to `paid` and raises the orders.
 * Freezing upfront matters — the cart could change, or a price could move,
 * between the redirect out and the webhook coming back.
 *
 * savings_transaction_id becomes nullable because most sessions no longer
 * have one: card checkouts are settled by Paystack, and plan fulfilments by
 * the plan's own payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Renaming a column leaves its foreign key under the old name, so
        // look the constraint up rather than assuming Laravel's convention.
        $this->dropForeignKeyOn('checkout_sessions', 'savings_transaction_id');

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->foreignId('savings_transaction_id')->nullable()->change();
            // pending | paid | abandoned
            $table->string('status', 20)->default('paid')->after('payment_method');
            $table->string('paystack_reference')->nullable()->unique()->after('status');
            $table->timestamp('paid_at')->nullable()->after('paystack_reference');
            // The basket, frozen at the moment the customer left to pay.
            $table->json('items_snapshot')->nullable()->after('paid_at');
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->foreign('savings_transaction_id')->references('id')->on('savings_transactions');
        });
    }

    public function down(): void
    {
        $this->dropForeignKeyOn('checkout_sessions', 'savings_transaction_id');

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropUnique(['paystack_reference']);
            $table->dropColumn(['status', 'paystack_reference', 'paid_at', 'items_snapshot']);
            $table->foreignId('savings_transaction_id')->nullable(false)->change();
            $table->foreign('savings_transaction_id')->references('id')->on('savings_transactions');
        });
    }

    private function dropForeignKeyOn(string $table, string $column): void
    {
        $constraint = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column],
        );

        if ($constraint !== null) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($constraint->name));
        }
    }
};
