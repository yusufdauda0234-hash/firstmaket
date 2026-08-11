<?php

namespace App\Modules\AI\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The customer's own answer to a suggestion, written once and never
 * updated. If a plan later changes because of a recommendation, this row is
 * the evidence that a human said yes to it.
 */
class AssistantConfirmation extends Model
{
    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_DECLINED = 'declined';

    const UPDATED_AT = null;

    protected $fillable = ['recommendation_id', 'user_id', 'decision', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<AssistantRecommendation, $this> */
    public function recommendation(): BelongsTo { return $this->belongsTo(AssistantRecommendation::class, 'recommendation_id'); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
