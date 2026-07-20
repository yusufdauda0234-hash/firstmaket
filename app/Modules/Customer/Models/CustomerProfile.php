<?php

namespace App\Modules\Customer\Models;

use App\Models\User;
use App\Shared\Enums\IdentityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $bvn
 * @property string|null $nin
 * @property IdentityStatus $identity_status
 * @property string|null $default_state
 * @property string|null $default_lga
 * @property-read User $user
 */
class CustomerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'bvn',
        'nin',
        'identity_status',
        'default_state',
        'default_lga',
    ];

    protected function casts(): array
    {
        return [
            'bvn' => 'encrypted',
            'nin' => 'encrypted',
            'identity_status' => IdentityStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Product Target Plans stay locked until BVN or NIN verification passes;
     * Open Savings does not require this
     * (docs/firstmarket_Implementation_Plan.md Sprint 2 QA notes).
     */
    public function canActivateTargetPlans(): bool
    {
        return $this->identity_status === IdentityStatus::Verified;
    }
}
