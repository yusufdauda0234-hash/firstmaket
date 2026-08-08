<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The fields every product has, guaranteed to exist.
 *
 * These were only ever created by a seeder, so any environment where somebody
 * did not run it — or ran a targeted seed, or cleared the table — showed an
 * empty "Product fields" screen while the vendor form went on asking for name,
 * price and stock anyway. The screen claimed to describe the whole form and
 * described half of it.
 *
 * Idempotent on system_key, and it never touches a row that already exists:
 * the wording is editable in admin, and re-running must not undo somebody's
 * rewrite.
 *
 * Written through the query builder rather than the model. A migration runs
 * against the schema of the day it was written; a model is whatever it is
 * today, and one whose $fillable has moved on silently drops columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // [system_key, label, type, required, help text, sort]
        $fields = [
            ['category', 'Category', 'select', true, 'Decides where shoppers find it, and which extra fields you are asked for.', 0],
            ['name', 'Product name', 'text', true, 'What shoppers search for. Be specific — brand, model, size.', 1],
            ['description', 'Description', 'textarea', true, 'Condition, what is included, warranty, delivery notes.', 2],
            ['price_naira', 'Price (₦)', 'number', true, 'What the shopper pays. Pay Small Small divides this, it never changes it.', 3],
            ['stock_quantity', 'Stock quantity', 'number', true, 'How many you can actually ship today.', 4],
            ['images', 'Images', 'text', false, 'Up to 5. The first is the cover shoppers see in listings.', 5],
        ];

        foreach ($fields as [$key, $label, $type, $required, $help, $sort]) {
            if (DB::table('product_attributes')->where('system_key', $key)->exists()) {
                continue;
            }

            DB::table('product_attributes')->insert([
                'system_key' => $key,
                'category_id' => null,
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'options' => json_encode([]),
                'is_required' => $required,
                'is_active' => true,
                'help_text' => $help,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately nothing. These describe the vendor form, which exists
        // whether or not a row here does — removing them would only blank the
        // admin screen again.
    }
};
