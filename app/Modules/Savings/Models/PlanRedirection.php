<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable record of a savings redirection
 * (docs/FirstMaket-Database_Schema.md section 8): either the full Open
 * Savings balance moving into a plan (source_type = open_savings) or an
 * active plan switching to a different product carrying its full balance
 * (source_type = plan). Never a cash refund. Every row is also audit-logged.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source_type
 * @property int $source_id
 * @property int $target_plan_id
 * @property int|null $old_product_id
 * @property int $new_product_id
 * @property int $balance_transferred_kobo
 * @property int|null $old_target_price_kobo
 * @property int $new_target_price_kobo
 * @property Carbon|null $created_at
 * @property-read User $user
 * @property-read ProductTargetPlan $targetPlan
 * @property-read Product|null $oldProduct
 * @property-read Product $newProduct
 */
class PlanRedirection extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'target_plan_id',
        'old_product_id',
        'new_product_id',
        'balance_transferred_kobo',
        'old_target_price_kobo',
        'new_target_price_kobo',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'balance_transferred_kobo' => 'integer',
            'old_target_price_kobo' => 'integer',
            'new_target_price_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ProductTargetPlan, $this> */
    public function targetPlan(): BelongsTo
    {
        return $this->belongsTo(ProductTargetPlan::class, 'target_plan_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function oldProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'old_product_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function newProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'new_product_id');
    }
}
