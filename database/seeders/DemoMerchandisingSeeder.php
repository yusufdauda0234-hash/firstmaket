<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Fills demo compare-at prices and rating summaries for products that have
 * none, so the storefront cards render fully during development. Values are
 * deterministic per slug; real vendor input / the reviews module replace
 * them later. Safe to re-run.
 */
class DemoMerchandisingSeeder extends Seeder
{
    public function run(): void
    {
        Product::query()
            ->whereNull('compare_at_price_kobo')
            ->get()
            ->each(function (Product $product): void {
                $seed = crc32($product->slug);
                $markupPercent = 115 + ($seed % 31); // 115%–145% of app price

                $product->forceFill([
                    'compare_at_price_kobo' => intdiv($product->price_kobo * $markupPercent, 100),
                    'rating_average' => number_format(min(5.0, 3.5 + (($seed >> 3) % 16) / 10), 1),
                    'rating_count' => 12 + ($seed % 480),
                ])->save();
            });
    }
}
