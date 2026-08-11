<?php

namespace App\Modules\Customer\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class WishlistPriceAlert extends Model
{
    protected $fillable = ['user_id', 'product_id', 'threshold_percent', 'last_notified_price_kobo'];

    protected function casts(): array
    {
        return [
            'threshold_percent' => 'integer',
            'last_notified_price_kobo' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}