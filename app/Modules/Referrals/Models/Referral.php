<?php

namespace App\Modules\Referrals\Models;

use App\Models\User;
use App\Modules\Savings\Models\SavingsGoal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'referral_code',
        'status',
        'qualified_plan_id',
        'reward_amount',
        'reward_credited_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_amount' => 'integer',
            'reward_credited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    /** @return BelongsTo<SavingsGoal, $this> */
    public function qualifiedPlan(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'qualified_plan_id');
    }
}
