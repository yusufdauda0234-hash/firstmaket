<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\PlanPaymentMode;
use App\Shared\Enums\PlanStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A goal-based savings plan toward one product at a locked price
 * (docs/firstmarket-Database_Schema.md section 8). target_price_kobo is
 * copied from the product at creation and never changes automatically —
 * vendor price edits do not touch running plans. Pay At Once purchases are
 * plans with payment_mode = pay_at_once that reach Ready for Delivery in one
 * full contribution. All state changes go through PlanService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $product_id
 * @property int $target_price_kobo
 * @property PlanPaymentMode $payment_mode
 * @property PlanCadence|null $cadence
 * @property int|null $suggested_contribution_kobo
 * @property int $amount_saved_kobo
 * @property int $remaining_balance_kobo
 * @property string $progress_percentage
 * @property Carbon|null $expected_completion_date
 * @property PlanStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $last_contribution_at
 * @property Carbon|null $paused_at
 * @property string|null $pause_reason
 * @property Carbon|null $ready_for_delivery_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Product $product
 * @property-read Collection<int, PlanContribution> $contributions
 * @property-read Collection<int, PlanStatusEvent> $statusEvents
 */
class ProductTargetPlan extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'product_id',
        'target_price_kobo',
        'payment_mode',
        'cadence',
        'suggested_contribution_kobo',
        'amount_saved_kobo',
        'remaining_balance_kobo',
        'progress_percentage',
        'expected_completion_date',
        'status',
        'started_at',
        'last_contribution_at',
        'paused_at',
        'pause_reason',
        'ready_for_delivery_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'target_price_kobo' => 'integer',
            'payment_mode' => PlanPaymentMode::class,
            'cadence' => PlanCadence::class,
            'suggested_contribution_kobo' => 'integer',
            'amount_saved_kobo' => 'integer',
            'remaining_balance_kobo' => 'integer',
            'expected_completion_date' => 'date',
            'status' => PlanStatus::class,
            'started_at' => 'datetime',
            'last_contribution_at' => 'datetime',
            'paused_at' => 'datetime',
            'ready_for_delivery_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<PlanContribution, $this> */
    public function contributions(): HasMany
    {
        return $this->hasMany(PlanContribution::class, 'plan_id');
    }

    /** @return HasMany<PlanStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(PlanStatusEvent::class, 'plan_id');
    }
}
