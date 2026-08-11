<?php

namespace App\Modules\Returns\Models;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money going back to a customer.
 *
 * This is the first and only outward money path in the system. Everything
 * about it is deliberately narrow:
 *
 * - `issued_by` records the admin who authorised it. Nothing a customer or a
 *   scheduler can reach creates one of these.
 * - `gateway_reference` is unique at the database level, which is what makes a
 *   retry safe: the second attempt cannot insert, so it cannot pay again.
 * - `destination` is either the card that paid, or credit on the Pay Small
 *   Small plan the order came from. A plan never refunds as cash, which is the
 *   rule the whole savings design rests on.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $return_request_id
 * @property int $order_id
 * @property int $customer_id
 * @property int|null $issued_by
 * @property int $amount_kobo
 * @property string $destination
 * @property string $status
 * @property string|null $gateway_reference
 * @property string|null $failure_reason
 * @property Carbon|null $completed_at
 */
class Refund extends Model
{
    use HasUuid;

    public const DESTINATION_CARD = 'card';

    public const DESTINATION_PLAN_CREDIT = 'plan_credit';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'return_request_id',
        'order_id',
        'customer_id',
        'issued_by',
        'amount_kobo',
        'destination',
        'status',
        'gateway_reference',
        'failure_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
