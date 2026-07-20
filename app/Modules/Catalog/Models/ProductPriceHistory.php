<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int $old_price_kobo
 * @property int $new_price_kobo
 * @property int|null $changed_by
 * @property Carbon $created_at
 * @property-read Product $product
 */
class ProductPriceHistory extends Model
{
    const UPDATED_AT = null;

    protected $table = 'product_price_history';

    protected $fillable = [
        'product_id',
        'old_price_kobo',
        'new_price_kobo',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'old_price_kobo' => 'integer',
            'new_price_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
