<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Logistics\Models\DeliveryAssignment;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Casts\Uppercase;
use App\Shared\Enums\OrderStatus;
use App\Shared\Traits\HasUuid;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A fulfillment order, always created from a checkout session
 * (docs/FirstMaket-Database_Schema.md section 9) — either a cart paid in
 * full there and then, or a savings goal that reached its target, in which
 * case savings_goal_id is also set. Delivery address is captured once, on
 * the checkout screen. locked_price/commission/vendor_earning are snapshots
 * frozen at creation; later rate changes never alter existing orders. All state
 * changes go through OrderService. Vendor-facing views must never expose
 * customer identity or the delivery address.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $savings_goal_id
 * @property int|null $checkout_session_id
 * @property int $customer_id
 * @property int $vendor_id
 * @property int $product_id
 * @property string $delivery_address
 * @property string $state
 * @property string $lga
 * @property OrderStatus $status
 * @property int $locked_price_kobo
 * @property string $commission_rate_percent
 * @property string $commission_source Which rule set the rate: vendor, category or default.
 * @property int $commission_amount_kobo
 * @property int $vendor_earning_amount_kobo
 * @property Carbon|null $vendor_notified_at
 * @property Carbon|null $prepare_due_at
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $delivery_confirmed_at
 * @property Carbon|null $earnings_credited_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SavingsGoal|null $savingsGoal
 * @property-read CheckoutSession|null $checkoutSession
 * @property-read User $customer
 * @property-read VendorProfile $vendor
 * @property-read Product $product
 * @property-read Collection<int, OrderStatusEvent> $statusEvents
 * @property-read Collection<int, VendorPreparationEvent> $preparationEvents
 * @property-read Shipment|null $shipment
 * @property-read Collection<int, DeliveryAssignment> $deliveryAssignments
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuid;

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    protected $fillable = [
        'savings_goal_id',
        'checkout_session_id',
        // Assignable. ShipmentBuilder sets it through a query-builder update,
        // which bypasses this list — so leaving it out worked by accident and
        // silently dropped the value from any ordinary create().
        'shipment_id',
        'customer_id',
        'vendor_id',
        'product_id',
        'delivery_address',
        'state',
        'lga',
        'recipient_name',
        'recipient_phone',
        'landmark',
        'status',
        'locked_price_kobo',
        'commission_rate_percent',
        'commission_source',
        'commission_amount_kobo',
        'promo_discount_kobo',
        'goods_paid_at',
        'vendor_earning_amount_kobo',
        'vendor_notified_at',
        'prepare_due_at',
        'confirmed_by',
        'confirmed_at',
        'delivered_at',
        'delivery_confirmed_at',
        'earnings_credited_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            // Free text that goes on the delivery label, so one casing
            // throughout. `state` is deliberately NOT cast: it is validated
            // against the fixed Nigeria::STATES list, and storing "KADUNA"
            // where the list says "Kaduna" would fail that check the moment a
            // saved address is ever re-submitted.
            'delivery_address' => Uppercase::class,
            'lga' => Uppercase::class,
            'recipient_name' => Uppercase::class,
            'locked_price_kobo' => 'integer',
            'commission_amount_kobo' => 'integer',
            'promo_discount_kobo' => 'integer',
            'goods_paid_at' => 'datetime',
            'vendor_earning_amount_kobo' => 'integer',
            'vendor_notified_at' => 'datetime',
            'prepare_due_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_confirmed_at' => 'datetime',
            'earnings_credited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SavingsGoal, $this> */
    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    /** @return BelongsTo<CheckoutSession, $this> */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<VendorProfile, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<OrderStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class);
    }

    /** @return HasMany<VendorPreparationEvent, $this> */
    public function preparationEvents(): HasMany
    {
        return $this->hasMany(VendorPreparationEvent::class);
    }

    /**
     * The parcel this unit travels in.
     *
     * Several orders share one — three of the same item bought together are
     * one box — which is why delivery hangs off the shipment and not here.
     *
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Courier assignments, on orders raised before shipments existed.
     *
     * Nothing new writes `delivery_assignments.order_id`; read the shipment's
     * assignments instead.
     *
     * @return HasMany<DeliveryAssignment, $this>
     */
    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }

    /** Preparation is overdue when Processing runs past the SLA deadline. */
    public function isPreparationOverdue(): bool
    {
        return $this->status === OrderStatus::Processing
            && $this->prepare_due_at !== null
            && $this->prepare_due_at->isPast();
    }
}
