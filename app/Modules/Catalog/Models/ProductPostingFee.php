<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Enums\PostingTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property PostingTier $tier
 * @property int $amount_kobo
 * @property string $payment_status
 * @property Carbon $created_at
 * @property-read Product $product
 */
class ProductPostingFee extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'tier',
        'amount_kobo',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'tier' => PostingTier::class,
            'amount_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
