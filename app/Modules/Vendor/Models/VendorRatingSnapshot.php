<?php

namespace App\Modules\Vendor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated copy of a vendor's standing, written whenever the tier changes.
 *
 * Kept so a tier change is visible rather than silently replacing what came
 * before — a vendor demoted from Gold deserves to see when and on what
 * numbers, and staff need it to answer the complaint.
 *
 * @property int $id
 * @property int $vendor_id
 * @property int|null $vendor_rating_tier_id
 * @property int $score
 * @property array<string, mixed> $metrics
 */
class VendorRatingSnapshot extends Model
{
    protected $fillable = ['vendor_id', 'vendor_rating_tier_id', 'score', 'metrics', 'captured_at'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'score' => 'integer', 'captured_at' => 'datetime'];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(VendorRatingTier::class, 'vendor_rating_tier_id');
    }
}
