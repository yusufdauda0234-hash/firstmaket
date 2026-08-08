<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Describe the regular ("was") price on the admin "Product fields" screen.
 *
 * Its own migration rather than an edit to the earlier ones, because those
 * have already run everywhere — extending their list would add the row on a
 * fresh database and nowhere else.
 *
 * Query builder, not the model: a migration runs against the schema of the day
 * it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('product_attributes')->where('system_key', 'compare_at_naira')->exists()) {
            return;
        }

        DB::table('product_attributes')->insert([
            'system_key' => 'compare_at_naira',
            'category_id' => null,
            'key' => 'compare_at_naira',
            'label' => 'Regular price (₦)',
            'type' => 'number',
            'options' => json_encode([]),
            'is_required' => false,
            'is_active' => true,
            'help_text' => 'Optional. The old price, shown struck through. Must be higher than what you are selling at.',
            'sort_order' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Nothing, matching its siblings: the field exists on the vendor form
        // whether or not a row describing it does.
    }
};
