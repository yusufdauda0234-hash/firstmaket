<?php

namespace App\Modules\AI\Models;

use App\Models\User;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Something the assistant thinks the customer might want to do.
 *
 * A recommendation on its own does nothing at all. It is inert until an
 * AssistantConfirmation exists saying the customer accepted it — and the
 * only code that acts on one checks for that row first.
 *
 * `payload` is frozen at the moment of suggestion so what the customer
 * confirms is what they were shown. If the numbers move underneath, the
 * recommendation expires rather than quietly acting on figures nobody
 * agreed to.
 */
class AssistantRecommendation extends Model
{
    use HasUuid;

    /** Suggest a smaller instalment over a longer run. */
    public const ACTION_RESCHEDULE = 'reschedule_plan';

    /** Suggest swapping to a cheaper product the plan could actually reach. */
    public const ACTION_SWITCH_TO_CHEAPER = 'switch_to_cheaper';

    /** Suggest pausing rather than falling further behind. */
    public const ACTION_PAUSE = 'pause_plan';

    /** Pure advice — nothing to act on, just something worth knowing. */
    public const ACTION_INFORMATION = 'information';

    public const STATUS_OFFERED = 'offered';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'conversation_id', 'user_id', 'savings_goal_id', 'action',
        'title', 'body', 'payload', 'evidence', 'status', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'evidence' => 'array', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** @return BelongsTo<AssistantConversation, $this> */
    public function conversation(): BelongsTo { return $this->belongsTo(AssistantConversation::class, 'conversation_id'); }

    /** @return BelongsTo<SavingsGoal, $this> */
    public function goal(): BelongsTo { return $this->belongsTo(SavingsGoal::class, 'savings_goal_id'); }

    /** @return HasOne<AssistantConfirmation, $this> */
    public function confirmation(): HasOne { return $this->hasOne(AssistantConfirmation::class, 'recommendation_id'); }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Still awaiting an answer, and still fresh enough to act on. */
    public function isActionable(): bool
    {
        return $this->status === self::STATUS_OFFERED
            && ! $this->hasExpired()
            && $this->action !== self::ACTION_INFORMATION;
    }
}
