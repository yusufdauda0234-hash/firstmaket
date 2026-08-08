<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Pay Small Small plan: one basket, one frozen price, paid off in fixed
 * installments until it is covered.
 *
 * Everything about the schedule is snapshotted from the PlanTerm the
 * customer chose, so an admin retiring or editing that term later cannot
 * change a plan already running. `paid_kobo` is the plan's own running
 * total — money belongs to this plan and nothing else, which is why there is
 * no customer balance anywhere in the system.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $target_kobo Goods plus delivery, frozen at creation so saving up cannot be outrun by a price rise.
 * @property int $delivery_fee_kobo The delivery share of the target, locked with the prices.
 * @property int|null $plan_term_id
 * @property PlanCadence|null $cadence Snapshot of the chosen term.
 * @property int $installments
 * @property int|null $duration_months
 * @property int $payments_made
 * @property int $extension_count
 * @property int $switch_count
 * @property int $installment_kobo
 * @property int $paid_kobo
 * @property Carbon|null $next_due_at
 * @property Carbon|null $first_payment_due_at
 * @property int|null $missed_payments_allowed
 * @property Carbon|null $dormancy_warned_at
 * @property Carbon|null $started_at
 * @property SavingsGoalStatus $status
 * @property string|null $delivery_address
 * @property string|null $state
 * @property string|null $lga
 * @property string|null $recipient_name
 * @property string|null $recipient_phone
 * @property string|null $landmark
 * @property Carbon|null $fulfilled_at
 * @property-read User $user
 * @property-read Collection<int, SavingsGoalItem> $items
 * @property-read Collection<int, PlanPayment> $payments
 * @property-read PlanTerm|null $term
 * @property-read Collection<int, Order> $orders
 */
class SavingsGoal extends Model
{
    use HasUuid;

    /**
     * Ceiling on the missed-payment walk.
     *
     * A daily plan abandoned two years ago would otherwise step the loop ~730
     * times to reach an answer that stopped mattering at the allowance. Any
     * plan this far behind is dormant several times over.
     */
    private const MAX_COUNTED_MISSES = 60;

    protected $fillable = [
        'user_id',
        'target_kobo',
        'delivery_fee_kobo',
        'plan_term_id',
        'cadence',
        'installments',
        'duration_months',
        'payments_made',
        'extension_count',
        'switch_count',
        'installment_kobo',
        'paid_kobo',
        'next_due_at',
        'first_payment_due_at',
        'missed_payments_allowed',
        'dormancy_warned_at',
        'started_at',
        'status',
        'delivery_address',
        'state',
        'lga',
        'recipient_name',
        'recipient_phone',
        'landmark',
        'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'target_kobo' => 'integer',
            'delivery_fee_kobo' => 'integer',
            'cadence' => PlanCadence::class,
            'installments' => 'integer',
            'duration_months' => 'integer',
            'payments_made' => 'integer',
            'extension_count' => 'integer',
            'switch_count' => 'integer',
            'installment_kobo' => 'integer',
            'paid_kobo' => 'integer',
            'status' => SavingsGoalStatus::class,
            'delivery_address' => 'encrypted',
            'next_due_at' => 'datetime',
            'first_payment_due_at' => 'datetime',
            'missed_payments_allowed' => 'integer',
            'dormancy_warned_at' => 'datetime',
            'started_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SavingsGoalItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SavingsGoalItem::class);
    }

    /** @return HasMany<PlanPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PlanPayment::class, 'savings_goal_id');
    }

    /** @return BelongsTo<PlanTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(PlanTerm::class, 'plan_term_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'savings_goal_id');
    }

    public function isSaving(): bool
    {
        return $this->status === SavingsGoalStatus::Saving;
    }

    public function remainingKobo(): int
    {
        return max(0, $this->target_kobo - $this->paid_kobo);
    }

    /**
     * How many scheduled payments have come and gone unpaid.
     *
     * Counted from `next_due_at`, which advances on every payment: the moment
     * it is in the past, one has been missed, and each further cadence period
     * that elapses is another. Returns 0 for a plan that is up to date, has
     * no schedule, or is no longer running.
     */
    public function missedPayments(): int
    {
        if (! $this->isSaving() || $this->next_due_at === null || $this->cadence === null) {
            return 0;
        }

        if ($this->next_due_at->isFuture()) {
            return 0;
        }

        $missed = 1;
        $due = $this->cadence->next($this->next_due_at);

        // Walk forward a period at a time rather than dividing by an assumed
        // period length — months are not a fixed number of days, and the
        // cadence already knows how to step itself.
        while ($due->isPast() && $missed < self::MAX_COUNTED_MISSES) {
            $missed++;
            $due = $this->cadence->next($due);
        }

        return $missed;
    }

    /**
     * True when the plan has missed more than its allowance.
     *
     * A null or zero allowance means the plan is never let go for inactivity,
     * which is what plans started before the policy existed carry.
     */
    public function isDormant(): bool
    {
        $allowed = (int) ($this->missed_payments_allowed ?? 0);

        if ($allowed <= 0) {
            return false;
        }

        return $this->missedPayments() > $allowed;
    }

    public function isCovered(): bool
    {
        return $this->paid_kobo >= $this->target_kobo;
    }

    public function progressPercent(): int
    {
        if ($this->target_kobo <= 0) {
            return 100;
        }

        return (int) min(100, floor($this->paid_kobo * 100 / $this->target_kobo));
    }

    /**
     * What to charge for the next payment: a full installment, or whatever
     * is left when that would overshoot the target.
     */
    public function nextPaymentKobo(): int
    {
        return min(max(1, $this->installment_kobo), $this->remainingKobo());
    }

    /**
     * Payments actually made.
     *
     * Counted, not derived from paid_kobo / installment_kobo: that division
     * assumes every instalment is the same size, which stops being true once
     * a plan is rescheduled onto a longer run — the new, smaller instalment
     * would divide the money already banked into payments nobody made.
     */
    public function installmentsPaid(): int
    {
        return (int) min($this->installments, $this->payments_made);
    }
}
