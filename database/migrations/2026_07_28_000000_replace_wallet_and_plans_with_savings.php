<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses the wallet, the Open Savings pot and Product Target Plans into a
 * single Savings balance.
 *
 * There used to be three places money could sit — a wallet funded by
 * Paystack, an Open Savings pot funded from the wallet, and per-product
 * plans funded from either — plus scheduling, redirection and eligibility
 * machinery on top. Customers only ever needed one: a balance that grows
 * until it covers what they want to buy.
 *
 * The wallet tables are *renamed* rather than dropped and recreated: their
 * append-only ledger with row-locked balance_before/after is exactly what
 * savings needs, and renaming keeps that design (and every existing row)
 * intact. Everything plan-shaped is dropped outright — all of those tables
 * were empty.
 *
 * savings_goals replaces product_target_plans with the two columns that
 * actually mattered: what you are saving towards, and how much it costs.
 */
return new class extends Migration
{
    /**
     * Every step is guarded so the migration can be re-run against a
     * partially-migrated database — which matters here because it renames
     * columns, and MariaDB's legacy rename grammar aborts the whole
     * statement if the source column has already gone.
     */
    public function up(): void
    {
        // ── 1. Detach everything pointing at the tables being dropped ──
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'plan_id')) {
                $table->dropConstrainedForeignId('plan_id');
            }
            if (Schema::hasColumn('orders', 'plan_item_id')) {
                $table->dropConstrainedForeignId('plan_item_id');
            }
            if (Schema::hasColumn('orders', 'plan_delivery_group_id')) {
                $table->dropColumn('plan_delivery_group_id');
            }
        });

        Schema::dropIfExists('plan_redirections');
        Schema::dropIfExists('plan_status_events');
        Schema::dropIfExists('plan_contributions');
        Schema::dropIfExists('plan_items');
        Schema::dropIfExists('open_savings');
        Schema::dropIfExists('product_target_plans');

        // ── 2. Wallet becomes savings, ledger and all ──
        if (Schema::hasTable('wallets')) {
            Schema::rename('wallets', 'savings');
        }
        if (Schema::hasTable('wallet_transactions')) {
            Schema::rename('wallet_transactions', 'savings_transactions');
        }

        $this->renameIfPresent('savings_transactions', 'wallet_id', 'savings_id');
        $this->renameIfPresent('receipts', 'wallet_transaction_id', 'savings_transaction_id');
        $this->renameIfPresent('checkout_sessions', 'wallet_transaction_id', 'savings_transaction_id');
        $this->renameIfPresent('settlement_reconciliation_items', 'wallet_transaction_id', 'savings_transaction_id');
        $this->renameIfPresent('paystack_transactions', 'wallet_transaction_id', 'savings_transaction_id');

        // ── 3. Goals: what the balance is being saved towards ──
        if (! Schema::hasTable('savings_goals')) {
            Schema::create('savings_goals', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // Frozen at creation: the whole point is that saving up cannot be
                // outrun by a price rise.
                $table->unsignedBigInteger('target_kobo');
                // saving | fulfilled | cancelled
                $table->string('status', 20)->default('saving');
                $table->text('delivery_address')->nullable();
                $table->string('state', 60)->nullable();
                $table->string('lga', 80)->nullable();
                $table->timestamp('fulfilled_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasTable('savings_goal_items')) {
            Schema::create('savings_goal_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained();
                $table->unsignedInteger('quantity')->default(1);
                // Snapshot, so the goal total still reconciles if the vendor
                // reprices the product mid-save.
                $table->unsignedBigInteger('unit_price_kobo');
                $table->timestamps();

                $table->unique(['savings_goal_id', 'product_id']);
            });
        }

        if (! Schema::hasColumn('orders', 'savings_goal_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('savings_goal_id')->nullable()->after('uuid')->constrained();
            });
        }
    }

    private function renameIfPresent(string $table, string $from, string $to): void
    {
        if (Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn($from, $to));
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('savings_goal_id');
        });

        Schema::dropIfExists('savings_goal_items');
        Schema::dropIfExists('savings_goals');

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->renameColumn('savings_transaction_id', 'wallet_transaction_id');
        });

        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->renameColumn('savings_transaction_id', 'wallet_transaction_id');
        });

        Schema::table('settlement_reconciliation_items', function (Blueprint $table) {
            $table->renameColumn('savings_transaction_id', 'wallet_transaction_id');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->renameColumn('savings_transaction_id', 'wallet_transaction_id');
        });

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->renameColumn('savings_id', 'wallet_id');
        });

        Schema::rename('savings_transactions', 'wallet_transactions');
        Schema::rename('savings', 'wallets');

        // The plan tables are not recreated: they were empty when this ran,
        // and rebuilding six tables plus their machinery on a rollback would
        // be reconstructing a feature, not reversing a migration.
    }
};
