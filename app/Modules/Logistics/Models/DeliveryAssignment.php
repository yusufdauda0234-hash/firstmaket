<?php

namespace App\Modules\Logistics\Models;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\DeliveryAssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A courier being given a parcel to carry.
 *
 * Points at a shipment, not an order: three kettles bought together are one
 * box and one trip, and assigning three of these for it put three identical
 * stops on a courier's list.
 *
 * `order_id` survives, nullable, on rows written before shipments existed —
 * they still say what they meant, and nothing new writes it.
 *
 * @property int $id
 * @property int|null $order_id Historic rows only.
 * @property int|null $shipment_id
 * @property int $logistics_user_id
 * @property int $assigned_by
 * @property Carbon $assigned_at
 * @property DeliveryAssignmentStatus $status
 * @property-read Shipment|null $shipment
 * @property-read User $logisticsUser
 * @property-read User $assignedBy
 */
class DeliveryAssignment extends Model
{
    protected $fillable = [
        'order_id',
        'shipment_id',
        'logistics_user_id',
        'assigned_by',
        'assigned_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'status' => DeliveryAssignmentStatus::class,
        ];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * The order this covered, on rows predating shipments.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function logisticsUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logistics_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', DeliveryAssignmentStatus::Assigned);
    }
}
