<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery address needs a person and a phone number, not just a street.
 *
 * Checkout was echoing the account name back at the customer with nowhere to
 * change it — so an order placed for a relative, or from an account whose
 * name defaulted to an email address, gave the courier nothing to work with.
 * Nigerian couriers call ahead; without a number the delivery fails.
 *
 * `landmark` is optional but earns its place here: outside the major
 * estates, "opposite the filling station" is how addresses actually work.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['checkout_sessions', 'savings_goals'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('recipient_name')->nullable()->after('lga');
                $blueprint->string('recipient_phone', 20)->nullable()->after('recipient_name');
                $blueprint->string('landmark')->nullable()->after('recipient_phone');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('recipient_name')->nullable()->after('lga');
            $table->string('recipient_phone', 20)->nullable()->after('recipient_name');
            $table->string('landmark')->nullable()->after('recipient_phone');
        });
    }

    public function down(): void
    {
        foreach (['checkout_sessions', 'savings_goals', 'orders'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['recipient_name', 'recipient_phone', 'landmark']);
            });
        }
    }
};
