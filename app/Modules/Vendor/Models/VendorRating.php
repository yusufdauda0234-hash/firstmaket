<?php

namespace App\Modules\Vendor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vendor's current standing, with the numbers that produced it.
 *
 * The inputs are stored beside the result deliberately: a vendor asking why
 * they are Silver is owed the actual figures, and a support agent should not
 * have to re-derive them from the orders table to answer.
 *
 * @property int $id
 * @property int $vendor_id
 * @property int|null $vendor_rating_tier_id
 * @property int $score
 * @property int $delivered_orders
 * @property int $rejected_orders
 * @property int $returned_orders
 * @property int $late_preparations
 * @property float|null $average_product_rating
 * @property Carbon|null $calculated_at
 */
class VendorRating extends Model
{
    protected $fillable = [
        'vendor_id',
        'vendor_rating_tier_id',
        'score',
        'delivered_orders',
        'rejected_orders',
        'returned_orders',
        'late_preparations',
        'average_product_rating',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'delivered_orders' => 'integer',
            'rejected_orders' => 'integer',
            'returned_orders' => 'integer',
            'late_preparations' => 'integer',
            'average_product_rating' => 'float',
            'calculated_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(VendorRatingTier::class, 'vendor_rating_tier_id');
    }
}
