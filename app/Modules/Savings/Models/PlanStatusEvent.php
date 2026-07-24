<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only history of plan status transitions
 * (docs/FirstMaket-Database_Schema.md section 8), mirroring the catalog's
 * product_status_events pattern.
 *
 * @property int $id
 * @property int $plan_id
 * @property string|null $old_status
 * @property string $new_status
 * @property int|null $changed_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property-read ProductTargetPlan $plan
 * @property-read User|null $changedBy
 */
class PlanStatusEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'plan_id',
        'old_status',
        'new_status',
        'changed_by',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductTargetPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductTargetPlan::class, 'plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
