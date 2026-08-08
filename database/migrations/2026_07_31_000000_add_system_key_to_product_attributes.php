<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the built-in product fields appear alongside the custom ones.
 *
 * Product name, price, stock and the rest were hardcoded in the vendor form and
 * invisible in admin, so the field manager looked empty while vendors clearly
 * saw fields — the inconsistency this fixes.
 *
 * A row carrying a system_key is backed by a real column on products, with its
 * own validation and business rules. Those rows are listed and their wording is
 * editable, but they can never be deleted, retyped or switched off: "Price" is
 * not optional, and no configuration screen should be able to pretend it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_attributes', 'system_key')) {
            return;
        }

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->string('system_key', 40)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_attributes', 'system_key')) {
            Schema::table('product_attributes', function (Blueprint $table) {
                $table->dropColumn('system_key');
            });
        }
    }
};
