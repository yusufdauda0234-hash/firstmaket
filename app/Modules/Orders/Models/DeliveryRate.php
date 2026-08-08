<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The delivery fee for one state, split into its two legs.
 *
 * FirstMaket collects from the vendor and delivers to the customer, so the
 * fee is two costs, not one: vendor → hub, then hub → customer. The customer
 * is quoted the sum — nobody wants an itemised courier invoice at checkout —
 * but keeping them apart is what lets one leg be repriced without guessing.
 *
 * The row with a null state is the default every other state falls back to,
 * so pricing the whole country takes one row and Lagos can still differ.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $state Null on the default row.
 * @property int $fee_kobo What the customer is charged to have it delivered.
 * @property int|null $free_threshold_kobo
 * @property bool $is_active
 * @property string|null $note
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $updatedBy
 */
class DeliveryRate extends Model
{
    use HasUuids;

    protected $fillable = [
        'state',
        'fee_kobo',
        'free_threshold_kobo',
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
            'fee_kobo' => 'integer',
            'free_threshold_kobo' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * What the customer is quoted.
     *
     * Kept as a method rather than reading fee_kobo everywhere: the fee used
     * to be two legs added together, and callers that went through this did
     * not have to change when it stopped being.
     */
    public function totalKobo(): int
    {
        return $this->fee_kobo;
    }

    public function isDefault(): bool
    {
        return $this->state === null;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
