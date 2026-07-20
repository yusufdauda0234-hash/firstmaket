<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Attaches the bundled demo photos (storage/app/public/products/demo) to any
 * product that has no images yet, matching on slug. Safe to re-run.
 */
class DemoProductImagesSeeder extends Seeder
{
    public function run(): void
    {
        Product::query()
            ->with('images')
            ->get()
            ->each(function (Product $product): void {
                // Primary photo plus optional -2/-3 gallery angles.
                $candidates = [
                    "products/demo/{$product->slug}.jpg",
                    "products/demo/{$product->slug}-2.jpg",
                    "products/demo/{$product->slug}-3.jpg",
                ];

                foreach ($candidates as $order => $path) {
                    if (! Storage::disk('public')->exists($path)) {
                        continue;
                    }

                    if ($product->images->contains('path', $path)) {
                        continue;
                    }

                    $product->images()->create([
                        'path' => $path,
                        'sort_order' => $order,
                        'is_primary' => $order === 0 && ! $product->images->contains('is_primary', true),
                    ]);
                }
            });
    }
}
