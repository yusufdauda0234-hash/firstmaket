<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Campaign extends Model
{
    protected $fillable = ['uuid', 'name', 'type', 'description', 'starts_at', 'ends_at', 'is_active', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (self $campaign) => $campaign->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'campaign_products')
            ->withPivot(['sale_price_kobo', 'stock_cap', 'sold_quantity'])
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}