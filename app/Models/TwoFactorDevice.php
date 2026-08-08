<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser that has already passed the 2FA challenge and may skip it until
 * the trust expires.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $label
 * @property Carbon $expires_at
 */
class TwoFactorDevice extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'label',
        'ip_address',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
