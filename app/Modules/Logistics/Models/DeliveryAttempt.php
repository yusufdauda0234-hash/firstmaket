<?php

namespace App\Modules\Logistics\Models;

use App\Models\User;
use App\Shared\Enums\DeliveryOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One trip to a doorstep and how it ended.
 *
 * Kept even when the next attempt succeeds. Two failed runs to an address
 * that eventually worked is a real cost and a real signal — an address that
 * needs a phone call first, or a customer who is never in before six — and
 * only a per-attempt record can say so.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int|null $courier_user_id
 * @property int $attempt_no
 * @property DeliveryOutcome $outcome
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class DeliveryAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shipment_id',
        'courier_user_id',
        'attempt_no',
        'outcome',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => DeliveryOutcome::class,
            'attempt_no' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_user_id');
    }
}
