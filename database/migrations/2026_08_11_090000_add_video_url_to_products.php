<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional link to a video of the product — a YouTube demo or unboxing.
 *
 * Stored as the vendor's own link rather than an extracted id, so the original
 * is still there if the set of supported providers ever changes. What the page
 * embeds is always rebuilt from the id by VideoLink, never this string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('video_url', 2048)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('video_url');
        });
    }
};
