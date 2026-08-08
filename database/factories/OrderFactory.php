<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $product = Product::factory()->approved();

        return [
            'uuid' => (string) Str::uuid(),
            'customer_id' => User::factory(),
            'vendor_id' => fn (array $attributes) => Product::query()
                ->whereKey($attributes['product_id'])
                ->value('vendor_id'),
            'product_id' => $product,
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
            'recipient_name' => 'Musa Ibrahim',
            'recipient_phone' => '08031234567',
            'status' => OrderStatus::Pending,
            'locked_price_kobo' => 50_000_00,
            // A 10% commission split, consistent with the columns' meaning so
            // a factory-made order still reconciles.
            'commission_rate_percent' => '10.00',
            'commission_amount_kobo' => 5_000_00,
            'vendor_earning_amount_kobo' => 45_000_00,
        ];
    }
}
