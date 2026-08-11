<?php

namespace App\Modules\Returns\Models;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ReturnReason;
use App\Shared\Enums\ReturnStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One customer's request to send one order back.
 *
 * The policy fields are a snapshot, not a lookup. `policy_window_days`,
 * `return_delivery_paid_by` and `required_unopened` are copied in when the
 * case opens, so changing the published policy later cannot rewrite the terms
 * of a case already running — the customer agreed to what the product page
 * said on the day they opened it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int $customer_id
 * @property int $vendor_id
 * @property ReturnReason $reason
 * @property string|null $reason_note
 * @property ReturnStatus $status
 * @property int $policy_window_days
 * @property string $return_delivery_paid_by
 * @property bool $required_unopened
 * @property int $refundable_kobo
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $received_at
 * @property Carbon|null $resolved_at
 * @property-read Order $order
 * @property-read User $customer
 * @property-read VendorProfile $vendor
 * @property-read Collection<int, ReturnEvent> $events
 * @property-read Collection<int, ReturnEvidence> $evidence
 * @property-read Refund|null $refund
 */
class ReturnRequest extends Model
{
    use HasUuid;

    protected $fillable = [
        'order_id',
        'customer_id',
        'vendor_id',
        'reason',
        'reason_note',
        'status',
        'policy_window_days',
        'return_delivery_paid_by',
        'required_unopened',
        'refundable_kobo',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'received_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => ReturnReason::class,
            'status' => ReturnStatus::class,
            'policy_window_days' => 'integer',
            'required_unopened' => 'boolean',
            'refundable_kobo' => 'integer',
            'reviewed_at' => 'datetime',
            'received_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReturnEvent::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ReturnEvidence::class);
    }

    public function refund(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Still moving — neither refunded, rejected nor cancelled. */
    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }

    /** FirstMaket covers sending it back, because the fault was not the customer's. */
    public function platformPaysReturnDelivery(): bool
    {
        return $this->return_delivery_paid_by === 'platform';
    }
}
