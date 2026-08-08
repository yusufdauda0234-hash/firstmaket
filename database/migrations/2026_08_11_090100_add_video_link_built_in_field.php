<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Describe the new video link field on the admin "Product fields" screen.
 *
 * A sibling of 2026_08_10_090000, and separate from it for the same reason
 * that one exists: that migration has already run everywhere, so extending its
 * list would add the row on a fresh database and nowhere else.
 *
 * Query builder, not the model — a migration runs against the schema of the
 * day it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('product_attributes')->where('system_key', 'video_url')->exists()) {
            return;
        }

        DB::table('product_attributes')->insert([
            'system_key' => 'video_url',
            'category_id' => null,
            'key' => 'video_url',
            'label' => 'Video link',
            'type' => 'url',
            'options' => json_encode([]),
            'is_required' => false,
            'is_active' => true,
            'help_text' => 'Optional. A YouTube or Vimeo link — a demo or unboxing plays on the product page.',
            'sort_order' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Nothing, matching its sibling: the field exists on the vendor form
        // whether or not a row describing it does.
    }
};
