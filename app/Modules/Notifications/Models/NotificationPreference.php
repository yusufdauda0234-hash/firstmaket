<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user, per-category channel toggles
 * (docs/FirstMaket-Database_Schema.md section 10). Missing rows fall back
 * to the category defaults in NotificationPreferenceService.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationCategory $category
 * @property bool $email_enabled
 * @property bool $sms_enabled
 * @property bool $browser_enabled
 * @property-read User $user
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'email_enabled',
        'sms_enabled',
        'browser_enabled',
    ];

    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'browser_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
