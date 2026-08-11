<?php

namespace App\Modules\Payments\Models;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\AutomaticDebitStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A standing instruction to charge one saved card for one plan's instalment.
 *
 * No card details live here — only a reference to the reusable authorization
 * Paystack issued. Nothing in this model can move money outward; the only
 * direction is customer → plan, and even then it does not credit anything
 * itself (the verified webhook does).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $savings_goal_id
 * @property int|null $payment_authorization_id
 * @property int $amount_kobo
 * @property AutomaticDebitStatus $status
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property Carbon|null $last_succeeded_at
 * @property Carbon|null $last_failed_at
 * @property int $failure_count
 * @property string|null $last_error
 * @property-read User $user
 * @property-read SavingsGoal $goal
 * @property-read PaymentAuthorization|null $authorization
 */
class AutomaticDebit extends Model
{
    use HasUuid;

    /**
     * Consecutive failures tolerated before the debit stops and asks for the
     * card again: the first attempt, then one retry a day later.
     *
     * The constants are the shipped defaults; the accessors below are what the
     * service actually reads, so staff can tune both without a deploy.
     */
    public const MAX_FAILURES = 2;

    /** How long to wait before that single retry. */
    public const RETRY_AFTER_HOURS = 24;

    public static function maxFailures(): int
    {
        return max(1, (int) Setting::get('automatic_debit.max_failures', self::MAX_FAILURES));
    }

    public static function retryAfterHours(): int
    {
        return max(1, (int) Setting::get('automatic_debit.retry_after_hours', self::RETRY_AFTER_HOURS));
    }

    protected $fillable = [
        'user_id',
        'savings_goal_id',
        'payment_authorization_id',
        'amount_kobo',
        'status',
        'next_run_at',
        'last_run_at',
        'last_succeeded_at',
        'last_failed_at',
        'failure_count',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'status' => AutomaticDebitStatus::class,
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'payment_authorization_id');
    }

    public function isActive(): bool
    {
        return $this->status === AutomaticDebitStatus::Active;
    }

    /** The retry has been used up — only a fresh card restarts this. */
    public function needsReauthorization(): bool
    {
        return $this->status === AutomaticDebitStatus::NeedsReauthorization;
    }
}
