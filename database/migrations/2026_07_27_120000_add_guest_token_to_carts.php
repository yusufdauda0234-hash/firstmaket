<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest carts move out of the PHP session and into this table, keyed by a
 * long-lived cookie token instead of a user id.
 *
 * The session copy died with SESSION_LIFETIME (two hours idle), which is far
 * short of what shoppers expect — Amazon, AliExpress and Jumia all keep a
 * signed-out cart server-side against a months-long device cookie, and
 * Shopify keeps one for two weeks. Storing it here also means one storage
 * path for both guest and signed-in carts.
 *
 * user_id becomes nullable (a guest cart has none) and token is nullable
 * (a signed-in cart has none); both stay unique, and MariaDB permits any
 * number of NULLs in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The unique index on user_id is what the foreign key uses, so the
        // constraint has to come off before the column can be altered.
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('token', 64)->nullable()->unique()->after('user_id');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Guest carts cannot survive a column that demands a user.
        DB::table('carts')->whereNull('user_id')->delete();

        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
