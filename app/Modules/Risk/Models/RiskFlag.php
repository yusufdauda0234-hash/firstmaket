<?php

namespace App\Modules\Risk\Models;

use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something worth a human looking at.
 *
 * Emphatically not an enforcement mechanism. Nothing in this system suspends
 * an account, blocks a payment or cancels a plan because a flag fired — a
 * heuristic that locks someone out of money they have saved is a worse
 * outcome than the fraud it was guarding against, and the phase plan requires
 * a test proving it.
 *
 * The evidence is stored with the flag so a reviewer sees the numbers that
 * tripped it rather than being asked to trust the label.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property int|null $vendor_id
 * @property string $rule
 * @property string $severity
 * @property string $summary
 * @property array<string, mixed>|null $evidence
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property string|null $outcome
 */
class RiskFlag extends Model
{
    use HasUuid;

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    /** What a reviewer concluded. Recorded, never acted on automatically. */
    public const OUTCOME_NO_ACTION = 'no_action';

    public const OUTCOME_WATCHING = 'watching';

    public const OUTCOME_ACTIONED = 'actioned';

    protected $fillable = [
        'user_id',
        'vendor_id',
        'subject_key',
        'rule',
        'severity',
        'summary',
        'evidence',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
