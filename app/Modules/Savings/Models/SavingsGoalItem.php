<?php

namespace App\Modules\Savings\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product line inside a savings goal, with the unit price snapshotted at
 * the moment the goal was created — that snapshot is the promise that saving
 * up locks the price in.
 *
 * @property int $id
 * @property int $savings_goal_id
 * @property int $product_id
 * @property int $quantity
 * @property int $unit_price_kobo
 * @property-read SavingsGoal $goal
 * @property-read Product $product
 */
class SavingsGoalItem extends Model
{
    protected $fillable = [
        'savings_goal_id',
        'product_id',
        'quantity',
        'unit_price_kobo',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_kobo' => 'integer',
        ];
    }

    /** @return BelongsTo<SavingsGoal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotalKobo(): int
    {
        return $this->unit_price_kobo * $this->quantity;
    }
}
