<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'vendor_id' => VendorProfile::factory(),
            'category_id' => Category::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->paragraph(),
            'price_kobo' => fake()->numberBetween(50_000_00, 500_000_00),
            'stock_quantity' => fake()->numberBetween(1, 20),
            'status' => ProductStatus::Draft,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => ProductStatus::Approved,
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => ProductStatus::PendingApproval,
            'submitted_at' => now(),
        ]);
    }
}
