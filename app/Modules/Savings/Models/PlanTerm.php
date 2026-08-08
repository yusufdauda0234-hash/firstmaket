<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Shared\Enums\PlanCadence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Pay Small Small term the business offers: a cadence plus how many
 * installments it runs for. Admins own this list — the checkout only ever
 * shows what is active here, and the installment amount is the product's
 * locked price divided by `installments`.
 *
 * A running plan snapshots these values, so editing or retiring a term never
 * changes what an existing customer already agreed to.
 *
 * @property int $id
 * @property string $name
 * @property PlanCadence $cadence
 * @property int $installments
 * @property int $min_target_kobo Terms are hidden below this cart total.
 * @property int $first_payment_due_days 0 = pay at checkout.
 * @property int $missed_payments_allowed 0 = never let go for inactivity.
 * @property bool $is_active
 * @property int $sort_order
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $updatedBy
 */
class PlanTerm extends Model
{
    protected $fillable = [
        'name',
        'cadence',
        'duration_months',
        'installments',
        'min_target_kobo',
        'first_payment_due_days',
        'missed_payments_allowed',
        'is_active',
        'sort_order',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'cadence' => PlanCadence::class,
            'duration_months' => 'integer',
            'installments' => 'integer',
            'min_target_kobo' => 'integer',
            'first_payment_due_days' => 'integer',
            'missed_payments_allowed' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * How long an up-front plan has to actually settle.
     *
     * The plan is created before the customer reaches Paystack, so it cannot
     * be judged unpaid the instant they are redirected — a card can take a
     * few minutes and the webhook can lag. A day is generous enough to never
     * catch a genuine payment, short enough that an abandoned checkout is not
     * still holding a locked price tomorrow.
     */
    private const UPFRONT_SETTLEMENT_HOURS = 24;

    /** True when the first instalment is due at checkout, not later. */
    public function paysUpfront(): bool
    {
        return $this->first_payment_due_days === 0;
    }

    /** The deadline to stamp on a plan starting now. */
    public function firstPaymentDueAt(): Carbon
    {
        return $this->paysUpfront()
            ? now()->addHours(self::UPFRONT_SETTLEMENT_HOURS)
            : now()->addDays($this->first_payment_due_days);
    }

    /** Plain-English deadline for the checkout and the admin list. */
    public function firstPaymentLabel(): string
    {
        if ($this->paysUpfront()) {
            return 'First payment today';
        }

        $days = $this->first_payment_due_days;

        return 'First payment within '.$days.' day'.($days === 1 ? '' : 's');
    }

    /**
     * Keep the payment count tied to the duration.
     *
     * Derived on save rather than trusted from input, so no code path — form,
     * seeder, console — can leave a term claiming "3 months" while charging 13
     * weekly payments.
     */
    protected static function booted(): void
    {
        static::saving(function (self $term) {
            $cadence = $term->cadence instanceof PlanCadence
                ? $term->cadence
                : PlanCadence::from((string) $term->cadence);

            $term->duration_months = max(1, (int) $term->duration_months);
            $term->installments = $cadence->installmentsFor($term->duration_months);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * What each payment costs on a target of $targetKobo. Rounded up so the
     * instalments always cover the price — the final one absorbs the
     * rounding rather than leaving a few kobo outstanding forever.
     */
    public function installmentKoboFor(int $targetKobo): int
    {
        return (int) ceil($targetKobo / max(1, $this->installments));
    }

    public function durationLabel(): string
    {
        return $this->cadence->durationLabel($this->duration_months);
    }
}
