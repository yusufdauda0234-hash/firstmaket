<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A currency the storefront can show prices in.
 *
 * Display only. Every charge is settled in NGN by Paystack, so nothing here
 * touches an order total, a ledger row, or a plan instalment — see the
 * `display_currencies` migration for why the rate is data and not config.
 *
 * @property int $id
 * @property string $code
 * @property string $symbol
 * @property string $name
 * @property string $units_per_naira
 * @property int $decimals
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon $updated_at
 */
class DisplayCurrency extends Model
{
    protected $fillable = [
        'code',
        'symbol',
        'name',
        'units_per_naira',
        'decimals',
        'is_active',
        'sort_order',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'decimals' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('code');
    }

    /** The naira itself — the currency everything is priced and settled in. */
    public function isBase(): bool
    {
        return $this->code === 'NGN';
    }

    public function rate(): float
    {
        return (float) $this->units_per_naira;
    }

    /**
     * Shape handed to the frontend, which does the actual formatting through
     * Intl so it matches the viewer's locale conventions.
     *
     * @return array{code: string, symbol: string, name: string, rate: float, decimals: int, isBase: bool}
     */
    public function toDisplayArray(): array
    {
        return [
            'code' => $this->code,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'rate' => $this->rate(),
            'decimals' => $this->decimals,
            'isBase' => $this->isBase(),
        ];
    }
}
