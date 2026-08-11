<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A home-page hero carousel slide, admin-authored (Admin/Merchandising/HeroSlides)
 * instead of hardcoded in the frontend. Copy, theme and destination live here;
 * offer_type = 'from_price'/'campaign_discount' figures are computed from real
 * catalog/campaign data at render time rather than stored, so a slide never
 * goes stale the way a hand-typed "60% OFF" would.
 */
class HeroSlide extends Model
{
    protected $fillable = [
        'uuid', 'eyebrow', 'title', 'description', 'cta_label', 'cta_target',
        'theme', 'emoji', 'offer_type', 'offer_label', 'offer_value',
        'is_active', 'sort_order', 'updated_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $slide) => $slide->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
