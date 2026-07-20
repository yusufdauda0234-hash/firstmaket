<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per attempted notification send
 * (docs/firstmarket-Database_Schema.md section 10) — the raw material for
 * delivery-failure monitoring. Written by RecordNotificationDelivery on the
 * framework's NotificationSent/NotificationFailed events.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $notification_id
 * @property string $channel
 * @property string|null $provider
 * @property string $status
 * @property string|null $provider_reference
 * @property string|null $error_message
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property-read User $user
 */
class NotificationDelivery extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'notification_id',
        'channel',
        'provider',
        'status',
        'provider_reference',
        'error_message',
        'sent_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
