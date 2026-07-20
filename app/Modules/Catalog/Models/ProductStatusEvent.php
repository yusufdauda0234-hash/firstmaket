<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property string|null $old_status
 * @property string $new_status
 * @property int|null $changed_by
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Product $product
 * @property-read User|null $changedBy
 */
class ProductStatusEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'old_status',
        'new_status',
        'changed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
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
