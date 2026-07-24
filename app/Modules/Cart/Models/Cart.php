<?php

namespace App\Modules\Cart\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One persistent cart per customer (docs/FirstMaket-Database_Schema.md
 * section 8a). Items can come from any vendor; checkout logic lives in
 * CartService, not here.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property-read User $user
 * @property-read Collection<int, CartItem> $items
 */
class Cart extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
