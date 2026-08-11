<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CooperativeContribution extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['cooperative_cycle_id', 'user_id', 'plan_payment_id', 'amount_kobo'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<CooperativeCycle, $this> */
    public function cycle(): BelongsTo { return $this->belongsTo(CooperativeCycle::class, 'cooperative_cycle_id'); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** @return BelongsTo<PlanPayment, $this> */
    public function planPayment(): BelongsTo { return $this->belongsTo(PlanPayment::class, 'plan_payment_id'); }
}
