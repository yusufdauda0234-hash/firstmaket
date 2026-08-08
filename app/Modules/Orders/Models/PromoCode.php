<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A promotional code.
 *
 * Platform-funded: whatever it discounts comes out of FirstMaket's
 * commission, never the vendor's earning. The vendor is paid as though the
 * customer had paid full price.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code Stored upper-case.
 * @property string $type percent|fixed|free_delivery
 * @property string|null $percent_off
 * @property int|null $amount_off_kobo
 * @property int|null $max_discount_kobo
 * @property int $min_order_kobo
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $max_redemptions
 * @property int $max_per_customer
 * @property bool $first_order_only
 * @property bool $is_active
 */
class PromoCode extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'description',
        'type',
        'percent_off',
        'amount_off_kobo',
        'max_discount_kobo',
        'min_order_kobo',
        'starts_at',
        'ends_at',
        'max_redemptions',
        'max_per_customer',
        'first_order_only',
        'is_active',
        'created_by',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'amount_off_kobo' => 'integer',
            'max_discount_kobo' => 'integer',
            'min_order_kobo' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_redemptions' => 'integer',
            'max_per_customer' => 'integer',
            'first_order_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Codes are matched case-insensitively by storing them one way. Doing it
     * here rather than at every call site means no lookup can forget.
     */
    protected static function booted(): void
    {
        static::saving(function (self $promo) {
            $promo->code = strtoupper(trim($promo->code));
        });
    }

    /** @return HasMany<PromoRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    /** Redemptions that still count against the limits. */
    public function liveRedemptions(): HasMany
    {
        return $this->redemptions()->whereNull('released_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isWithinSchedule(): bool
    {
        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    /**
     * What this code takes off a basket of $subtotalKobo, before it is capped
     * against the commission available to fund it.
     *
     * Delivery codes return zero here: they discount the delivery fee, which
     * the caller applies separately because it is not part of the goods
     * subtotal.
     */
    public function discountOn(int $subtotalKobo, int $deliveryKobo = 0): int
    {
        $discount = match ($this->type) {
            'percent' => (int) round($subtotalKobo * (float) $this->percent_off / 100),
            'fixed' => (int) $this->amount_off_kobo,
            'free_delivery' => $deliveryKobo,
            default => 0,
        };

        if ($this->type === 'percent' && $this->max_discount_kobo !== null) {
            $discount = min($discount, $this->max_discount_kobo);
        }

        // Never more than the thing being discounted.
        $ceiling = $this->type === 'free_delivery' ? $deliveryKobo : $subtotalKobo;

        return max(0, min($discount, $ceiling));
    }

    public function label(): string
    {
        return match ($this->type) {
            'percent' => rtrim(rtrim((string) $this->percent_off, '0'), '.').'% off',
            'fixed' => '₦'.number_format((int) $this->amount_off_kobo / 100).' off',
            'free_delivery' => 'Free delivery',
            default => 'Discount',
        };
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
