<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Seeder;

/**
 * The six launch categories. Slugs must stay in sync with
 * config/firstmarket.php so public URLs remain stable
 * (docs/firstmarket_Implementation_Plan.md Sprint 0/3).
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('firstmarket.categories') as $index => $category) {
            Category::query()->updateOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'sort_order' => $index, 'is_active' => true],
            );
        }
    }
}
