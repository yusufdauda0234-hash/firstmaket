<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\ProductAttribute;
use Illuminate\Database\Seeder;

/**
 * The fields every product has, surfaced so the admin field manager shows the
 * whole vendor form rather than only the custom half.
 *
 * These mirror what StoreProductRequest already enforces; the seeder does not
 * create behaviour, it describes behaviour that exists. Wording is editable in
 * admin, so re-running only fills in rows that are missing — it never
 * overwrites a label someone has reworded.
 */
class BuiltInProductFieldSeeder extends Seeder
{
    public function run(): void
    {
        // [system_key, label, type, required, help text, sort]
        $fields = [
            ['category', 'Category', 'select', true, 'Decides where shoppers find it, and which extra fields you are asked for.', 0],
            ['name', 'Product name', 'text', true, 'What shoppers search for. Be specific — brand, model, size.', 1],
            ['description', 'Description', 'textarea', true, 'Condition, what is included, warranty, delivery notes.', 2],
            ['price_naira', 'Price (₦)', 'number', true, 'What the shopper pays. Pay Small Small divides this, it never changes it.', 3],
            ['stock_quantity', 'Stock quantity', 'number', true, 'How many you can actually ship today.', 4],
            ['images', 'Images', 'text', false, 'Up to 5. The first is the cover shoppers see in listings.', 5],
            ['video_url', 'Video link', 'url', false, 'Optional. A YouTube or Vimeo link — a demo or unboxing plays on the product page.', 6],
            ['compare_at_naira', 'Regular price (₦)', 'number', false, 'Optional. The old price, shown struck through. Must be higher than what you are selling at.', 7],
        ];

        foreach ($fields as [$key, $label, $type, $required, $help, $sort]) {
            ProductAttribute::query()->firstOrCreate(
                ['system_key' => $key],
                [
                    'category_id' => null,
                    'key' => $key,
                    'label' => $label,
                    'type' => $type,
                    'options' => [],
                    'is_required' => $required,
                    'is_active' => true,
                    'help_text' => $help,
                    'sort_order' => $sort,
                ],
            );
        }
    }
}
