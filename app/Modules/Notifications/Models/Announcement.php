<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Shared\Enums\NotificationCategory;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * One admin broadcast: what was said, who it went to, and down which
 * channels.
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property string $body
 * @property string $audience
 * @property int|null $role_id
 * @property int|null $user_id
 * @property list<string> $channels
 * @property NotificationCategory $category
 * @property int $recipients_count
 * @property int|null $sent_by
 * @property Carbon|null $sent_at
 */
class Announcement extends Model
{
    use HasFactory;
    use HasUuid;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_ROLE = 'role';

    public const AUDIENCE_USER = 'user';

    protected $fillable = [
        'title',
        'body',
        'audience',
        'role_id',
        'user_id',
        'channels',
        'category',
        'recipients_count',
        'sent_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'category' => NotificationCategory::class,
            'sent_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** The single recipient, when audience is 'user'. */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** Human description of who this went to, for the sent list. */
    public function audienceLabel(): string
    {
        return match ($this->audience) {
            self::AUDIENCE_ROLE => 'Role: '.($this->role?->name ?? 'deleted role'),
            self::AUDIENCE_USER => $this->recipient?->name ?? 'deleted user',
            default => 'Everyone',
        };
    }
}
