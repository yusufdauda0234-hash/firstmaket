<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Shared\Enums\ComplaintCategory;
use App\Shared\Enums\SupportChannel;
use App\Shared\Enums\TicketPriority;
use App\Shared\Enums\TicketStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer support ticket (docs/FirstMaket-Database_Schema.md section
 * 10) with its message thread. State changes go through SupportService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property int|null $assigned_to
 * @property SupportChannel $channel
 * @property string $subject
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $customer
 * @property-read User|null $assignee
 * @property-read Collection<int, SupportTicketMessage> $messages
 */
class SupportTicket extends Model
{
    use HasUuid;

    protected $fillable = [
        'customer_id',
        'assigned_to',
        'channel',
        'complaint_category',
        'about_order_id',
        'about_vendor_id',
        'subject',
        'status',
        'priority',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => SupportChannel::class,
            'complaint_category' => ComplaintCategory::class,
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }
}
