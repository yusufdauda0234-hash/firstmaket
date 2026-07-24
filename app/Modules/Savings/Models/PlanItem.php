<?php

namespace App\Modules\Savings\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One product bundled into a multi-product Product Target Plan (Sprint 8,
 * docs/FirstMaket-Database_Schema.md section 8a). Only present for bundled
 * plans — a single-product plan uses product_target_plans.product_id
 * directly and has no plan_items. locked_price_kobo is copied from the
 * product at bundle creation, same as a single-product plan's
 * target_price_kobo, and never changes automatically.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $product_id
 * @property int $vendor_id
 * @property int $locked_price_kobo
 * @property int $quantity
 * @property Carbon $created_at
 * @property-read ProductTargetPlan $plan
 * @property-read Product $product
 * @property-read VendorProfile $vendor
 */
class PlanItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'plan_id',
        'product_id',
        'vendor_id',
        'locked_price_kobo',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'locked_price_kobo' => 'integer',
            'quantity' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductTargetPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductTargetPlan::class, 'plan_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<VendorProfile, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }
}
