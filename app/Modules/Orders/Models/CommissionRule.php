<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One commission rule: what FirstMaket takes, on what, and between which
 * prices.
 *
 * @property int $id
 * @property string $uuid
 * @property string $scope_type global|category|vendor|product
 * @property int|null $scope_id
 * @property int $min_price_kobo Inclusive.
 * @property int|null $max_price_kobo Exclusive; null = no ceiling.
 * @property string $rate_percent
 * @property int|null $max_commission_kobo
 * @property bool $is_active
 * @property string|null $note
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CommissionRule extends Model
{
    use HasUuids;

    /**
     * How specific each scope is. A product rule beats a vendor rule beats a
     * category rule beats the global one, whatever order they were created
     * in.
     */
    public const SPECIFICITY = [
        'product' => 4,
        'vendor' => 3,
        'category' => 2,
        'global' => 1,
    ];

    protected $fillable = [
        'scope_type',
        'scope_id',
        'min_price_kobo',
        'max_price_kobo',
        'rate_percent',
        'max_commission_kobo',
        'is_active',
        'note',
        'updated_by',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'min_price_kobo' => 'integer',
            'max_price_kobo' => 'integer',
            'max_commission_kobo' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** True when $unitPriceKobo falls inside this rule's band. */
    public function coversPrice(int $unitPriceKobo): bool
    {
        if ($unitPriceKobo < $this->min_price_kobo) {
            return false;
        }

        // Exclusive ceiling, so touching bands never both match the same
        // price and the boundary belongs to exactly one of them.
        return $this->max_price_kobo === null || $unitPriceKobo < $this->max_price_kobo;
    }

    public function specificity(): int
    {
        return self::SPECIFICITY[$this->scope_type] ?? 0;
    }

    /**
     * Commission on one unit, with any ceiling applied. Never more than the
     * price itself — a rule that would take more than the sale is a
     * misconfiguration, not a debt.
     */
    public function commissionOn(int $unitPriceKobo): int
    {
        $commission = (int) round($unitPriceKobo * (float) $this->rate_percent / 100);

        if ($this->max_commission_kobo !== null) {
            $commission = min($commission, $this->max_commission_kobo);
        }

        return max(0, min($commission, $unitPriceKobo));
    }

    /** What this rule applies to, for the admin list. */
    public function scopeLabel(): string
    {
        return match ($this->scope_type) {
            'category' => Category::query()->whereKey($this->scope_id)->value('name') ?? 'Unknown category',
            'vendor' => VendorProfile::query()->whereKey($this->scope_id)->value('business_name') ?? 'Unknown vendor',
            'product' => Product::query()->whereKey($this->scope_id)->value('name') ?? 'Unknown product',
            default => 'Everything',
        };
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
