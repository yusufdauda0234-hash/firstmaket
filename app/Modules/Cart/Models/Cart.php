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
 * Exactly one of user_id / token is set: an account cart is found by user,
 * a guest cart by the long-lived cookie token GuestCart issues. The guest
 * row is deleted once MergeGuestCartOnLogin has folded it into an account.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string|null $token
 * @property-read User|null $user
 * @property-read Collection<int, CartItem> $items
 */
class Cart extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'token',
    ];

    protected $hidden = [
        // Whoever holds this owns the cart — it must never reach a payload.
        'token',
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
