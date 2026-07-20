<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Enums\PostingTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single-row admin settings for vendor posting fees
 * (docs/firstmarket_PRD_Laravel.md "Vendor fee settings"). Fee changes apply
 * only to new posts, never to already-submitted ones.
 */
/**
 * @property int $id
 * @property string $posting_mode
 * @property int $basic_fee_kobo
 * @property int $premium_fee_kobo
 * @property int $featured_fee_kobo
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class VendorFeeSetting extends Model
{
    protected $fillable = [
        'posting_mode',
        'basic_fee_kobo',
        'premium_fee_kobo',
        'featured_fee_kobo',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'basic_fee_kobo' => 'integer',
            'premium_fee_kobo' => 'integer',
            'featured_fee_kobo' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    public function isFreeMode(): bool
    {
        return $this->posting_mode === 'free';
    }

    public function feeFor(PostingTier $tier): int
    {
        return match ($tier) {
            PostingTier::Free => 0,
            PostingTier::Basic => $this->basic_fee_kobo,
            PostingTier::Premium => $this->premium_fee_kobo,
            PostingTier::Featured => $this->featured_fee_kobo,
        };
    }
}
