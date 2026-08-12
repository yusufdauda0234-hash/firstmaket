<?php

namespace App\Modules\Affiliates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a rank asks for before somebody may enter it.
 *
 * Rows rather than columns, because the whole point of the ladder being data
 * is that an admin can ask for "NIN" or "two trade references" next year
 * without anybody writing a migration.
 */
class AffiliateRankRequirement extends Model
{
    public const TYPE_DOCUMENT = 'document';

    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    protected $fillable = ['tier_id', 'label', 'help_text', 'type', 'is_required', 'sort_order'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<AffiliateTier, $this> */
    public function tier(): BelongsTo { return $this->belongsTo(AffiliateTier::class, 'tier_id'); }
}
