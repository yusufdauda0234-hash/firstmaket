<?php

namespace App\Modules\Logistics\Models;

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Casts\Uppercase;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One parcel: one pickup, one doorstep, however many units.
 *
 * Grouped by (checkout session, vendor), which is the only grouping that
 * describes a physical box. Orders stay one per unit because that is what
 * commission, promo share and payout are computed on; the shipment is the
 * delivery-side view over them.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $checkout_session_id
 * @property int $vendor_id
 * @property int $customer_id
 * @property ShipmentStatus $status
 * @property string $delivery_address
 * @property string $state
 * @property string $lga
 * @property string|null $recipient_name
 * @property string|null $recipient_phone
 * @property string|null $landmark
 * @property string|null $delivery_code
 * @property int $collect_on_delivery_kobo Cash the courier must take at the door.
 * @property string $goods_collection_method cash | customer_online | courier_online
 * @property Carbon|null $goods_paid_at
 * @property int|null $goods_paid_by
 * @property int $attempt_count
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $delivered_at
 * @property int|null $delivered_by
 * @property int|null $proof_overridden_by
 * @property-read Collection<int, Order> $orders
 * @property-read VendorProfile $vendor
 * @property-read User $customer
 */
class Shipment extends Model
{
    use HasUuid;

    /** After this many failures the shipment stops going back on the van. */
    public const MAX_ATTEMPTS = 3;

    /** Delivery attempts before a shipment goes back to the office. */
    public static function maxAttempts(): int
    {
        return max(1, (int) Setting::get('logistics.max_delivery_attempts', self::MAX_ATTEMPTS));
    }

    protected $fillable = [
        'checkout_session_id',
        'vendor_id',
        'customer_id',
        'status',
        'delivery_address',
        'state',
        'lga',
        'recipient_name',
        'recipient_phone',
        'landmark',
        'delivery_code',
        'collect_on_delivery_kobo',
        'goods_collection_method',
        'goods_paid_at',
        'goods_paid_by',
        'attempt_count',
        'dispatched_at',
        'delivered_at',
        'delivered_by',
        'proof_overridden_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            // Same casing rules as the order this was built from, so the
            // label a courier reads matches the label on the parcel.
            'delivery_address' => Uppercase::class,
            'lga' => Uppercase::class,
            'recipient_name' => Uppercase::class,
            'collect_on_delivery_kobo' => 'integer',
            'goods_paid_at' => 'datetime',
            'attempt_count' => 'integer',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Four digits, read aloud at the door.
     *
     * Not a security token — it is spoken over a bad line to a courier
     * holding a phone in one hand. It proves the parcel reached the person
     * expecting it, which is all it needs to do. `random_int` rather than
     * `rand` because this one number is what stands between a courier and
     * releasing a vendor's earnings.
     */
    public static function freshCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<DeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /** @return HasMany<DeliveryAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }

    /** @return BelongsTo<VendorProfile, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<CheckoutSession, $this> */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    /** The courier holding it right now, if anyone. */
    public function activeAssignment(): ?DeliveryAssignment
    {
        return $this->assignments()
            ->where('status', DeliveryAssignmentStatus::Assigned)
            ->latest('assigned_at')
            ->first();
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [ShipmentStatus::Delivered, ShipmentStatus::Cancelled]);
    }

    /**
     * Waiting to be given to a courier: dispatchable, and nobody holding it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingCourier(Builder $query): void
    {
        $query->whereIn('status', [
            ShipmentStatus::ReadyForPickup,
            ShipmentStatus::Packed,
            ShipmentStatus::Failed,
        ])->whereDoesntHave(
            'assignments',
            fn (Builder $assignment) => $assignment->where('status', DeliveryAssignmentStatus::Assigned),
        );
    }

    /**
     * Out of retries.
     *
     * Not the same as failed: a first failure goes back on tomorrow's van, a
     * third means something is wrong that another trip will not fix, and a
     * human has to look at it.
     */
    public function isExhausted(): bool
    {
        return $this->attempt_count >= self::maxAttempts()
            && $this->status === ShipmentStatus::Failed;
    }

    /** How many units are in the box. */
    public function unitCount(): int
    {
        return $this->orders()->count();
    }

    /** What the courier is carrying, in words. */
    public function contentsLabel(): string
    {
        $names = $this->orders->pluck('product.name')->filter()->countBy();

        if ($names->isEmpty()) {
            return 'Parcel';
        }

        return $names
            ->map(fn (int $count, string $name) => $count > 1 ? "{$name} ×{$count}" : $name)
            ->implode(', ');
    }

    public function destinationLabel(): string
    {
        return Str::of($this->lga)->trim().', '.$this->state;
    }
}
