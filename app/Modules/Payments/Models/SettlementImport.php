<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A batch of Paystack settlement lines imported for reconciliation
 * (docs/FirstMaket-Database_Schema.md section 7).
 *
 * @property int $id
 * @property string $provider
 * @property string|null $file_path
 * @property int|null $imported_by
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $importedBy
 * @property-read Collection<int, SettlementReconciliationItem> $items
 */
class SettlementImport extends Model
{
    protected $fillable = [
        'provider',
        'file_path',
        'imported_by',
        'status',
        'started_at',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** @return HasMany<SettlementReconciliationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SettlementReconciliationItem::class);
    }
}
