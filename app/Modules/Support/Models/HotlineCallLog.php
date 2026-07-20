<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Shared\Enums\IvrReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hotline call/callback request attached to a customer account
 * (docs/firstmarket-Database_Schema.md section 10), with the IVR reason
 * category routing it to the right queue.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $support_ticket_id
 * @property string $phone
 * @property IvrReason $reason
 * @property string|null $ivr_selection
 * @property string|null $call_reference
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $customer
 * @property-read SupportTicket|null $ticket
 */
class HotlineCallLog extends Model
{
    protected $fillable = [
        'customer_id',
        'support_ticket_id',
        'phone',
        'reason',
        'ivr_selection',
        'call_reference',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => IvrReason::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
