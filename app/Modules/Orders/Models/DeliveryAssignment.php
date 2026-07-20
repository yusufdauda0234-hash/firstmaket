<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Shared\Enums\DeliveryAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Assignment of an order to a Logistics Personnel user for pickup and
 * delivery (docs/firstmarket-Database_Schema.md section 9).
 *
 * @property int $id
 * @property int $order_id
 * @property int $logistics_user_id
 * @property int $assigned_by
 * @property Carbon $assigned_at
 * @property DeliveryAssignmentStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read User $logisticsUser
 * @property-read User $assignedBy
 */
class DeliveryAssignment extends Model
{
    protected $fillable = [
        'order_id',
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

    /** @return BelongsTo<Order, $this> */
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
}
