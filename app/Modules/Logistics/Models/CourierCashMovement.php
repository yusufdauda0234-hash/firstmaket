<?php

namespace App\Modules\Logistics\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of cash: into a courier's hands, or back to the office.
 *
 * The rows are the balance. Nothing stores a running total, because a stored
 * total is a number that can be wrong without anything else disagreeing with
 * it — and the whole point of this table is that a discrepancy has to be
 * visible.
 *
 * @property int $id
 * @property string $uuid
 * @property int $courier_user_id
 * @property string $type collection|remittance
 * @property int $amount_kobo
 * @property int|null $shipment_id Null on a remittance.
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 */
class CourierCashMovement extends Model
{
    use HasUuid;

    public const COLLECTION = 'collection';

    public const REMITTANCE = 'remittance';

    protected $fillable = [
        'courier_user_id',
        'type',
        'amount_kobo',
        'shipment_id',
        'confirmed_by',
        'confirmed_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_user_id');
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeCollections(Builder $query): void
    {
        $query->where('type', self::COLLECTION);
    }

    /**
     * Remittances the office has actually seen the money for.
     *
     * An unconfirmed remittance is a claim, not a fact, so it does not reduce
     * what a courier is carrying.
     *
     * @param  Builder<self>  $query
     */
    public function scopeConfirmedRemittances(Builder $query): void
    {
        $query->where('type', self::REMITTANCE)->whereNotNull('confirmed_at');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
