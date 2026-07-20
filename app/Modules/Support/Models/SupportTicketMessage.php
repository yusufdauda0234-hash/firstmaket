<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a support ticket thread
 * (docs/firstmarket-Database_Schema.md section 10).
 *
 * @property int $id
 * @property int $support_ticket_id
 * @property int $sender_id
 * @property string $message
 * @property string|null $channel
 * @property Carbon|null $created_at
 * @property-read SupportTicket $ticket
 * @property-read User $sender
 */
class SupportTicketMessage extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'support_ticket_id',
        'sender_id',
        'message',
        'channel',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
