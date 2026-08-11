<?php

namespace App\Modules\Affiliates\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raised concern about an affiliate's traffic or conversions.
 *
 * A flag never blocks money by itself — it puts the conversion in front of a
 * human. Automatic suspension on a heuristic would let anyone with a
 * grudge knock a partner offline by generating suspicious-looking clicks
 * against their link.
 */
class AffiliateFraudFlag extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISMISSED = 'dismissed';

    /** Same customer attributed and converting far too fast to be organic. */
    public const REASON_VELOCITY = 'velocity';

    /** Click and conversion share a fingerprint with the affiliate's own. */
    public const REASON_SELF_DEALING = 'self_dealing';

    /** Conversion value wildly out of line with the partner's history. */
    public const REASON_VALUE_ANOMALY = 'value_anomaly';

    protected $fillable = [
        'affiliate_id', 'conversion_id', 'reason', 'detail',
        'status', 'resolved_by', 'resolved_at', 'resolution_note',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return BelongsTo<AffiliateConversion, $this> */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(AffiliateConversion::class, 'conversion_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
