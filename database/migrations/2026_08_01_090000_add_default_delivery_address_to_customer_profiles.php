<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember where a customer has things delivered.
 *
 * The profile already carried default_state and default_lga but nothing
 * else, so checkout could only ever prefill half an address — in practice
 * the form came up blank every time and the street, landmark and recipient
 * had to be retyped for every order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('default_recipient_name')->nullable()->after('default_lga');
            $table->string('default_recipient_phone', 20)->nullable()->after('default_recipient_name');
            $table->string('default_address', 500)->nullable()->after('default_recipient_phone');
            $table->string('default_landmark', 160)->nullable()->after('default_address');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'default_recipient_name',
                'default_recipient_phone',
                'default_address',
                'default_landmark',
            ]);
        });
    }
};
