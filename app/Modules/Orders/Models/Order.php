<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Savings\Models\PlanItem;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\OrderStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A fulfillment order created either from a fully funded (Ready for
 * Delivery) Product Target Plan or, since Sprint 8, directly from a cart
 * full-payment checkout (docs/FirstMaket-Database_Schema.md section 9).
 * plan_id is null for a checkout-session order; checkout_session_id is null
 * for a plan order. plan_item_id + plan_delivery_group_id are set only when
 * this order came from a bundled multi-product plan (several orders share
 * one plan_id and plan_delivery_group_id in that case). Delivery address is
 * captured once — either upfront at cart checkout, or after full funding for
 * a plan. locked_price/commission/vendor_earning are snapshots frozen at
 * creation; later rate changes never alter existing orders. All state
 * changes go through OrderService. Vendor-facing views must never expose
 * customer identity or the delivery address.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $plan_id
 * @property int|null $checkout_session_id
 * @property int|null $plan_item_id
 * @property string|null $plan_delivery_group_id
 * @property int $customer_id
 * @property int $vendor_id
 * @property int $product_id
 * @property string $delivery_address
 * @property string $state
 * @property string $lga
 * @property OrderStatus $status
 * @property int $locked_price_kobo
 * @property string $commission_rate_percent
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
 * @property-read ProductTargetPlan|null $plan
 * @property-read CheckoutSession|null $checkoutSession
 * @property-read PlanItem|null $planItem
 * @property-read User $customer
 * @property-read VendorProfile $vendor
 * @property-read Product $product
 * @property-read Collection<int, OrderStatusEvent> $statusEvents
 * @property-read Collection<int, VendorPreparationEvent> $preparationEvents
 * @property-read Collection<int, DeliveryAssignment> $deliveryAssignments
 */
class Order extends Model
{
    use HasUuid;

    protected $fillable = [
        'plan_id',
        'checkout_session_id',
        'plan_item_id',
        'plan_delivery_group_id',
        'customer_id',
        'vendor_id',
        'product_id',
        'delivery_address',
        'state',
        'lga',
        'status',
        'locked_price_kobo',
        'commission_rate_percent',
        'commission_amount_kobo',
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
            'locked_price_kobo' => 'integer',
            'commission_amount_kobo' => 'integer',
            'vendor_earning_amount_kobo' => 'integer',
            'vendor_notified_at' => 'datetime',
            'prepare_due_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_confirmed_at' => 'datetime',
            'earnings_credited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductTargetPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductTargetPlan::class, 'plan_id');
    }

    /** @return BelongsTo<CheckoutSession, $this> */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    /** @return BelongsTo<PlanItem, $this> */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class);
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

    /** @return HasMany<DeliveryAssignment, $this> */
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
