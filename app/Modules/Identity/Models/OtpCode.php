<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Shared\Enums\OtpChannel;
use App\Shared\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time codes are stored hashed only — the plaintext code exists solely in
 * the SMS or email message (docs/FirstMaket_Security_Compliance.md).
 */
/**
 * @property int $id
 * @property int|null $user_id
 * @property OtpChannel $channel
 * @property string $destination
 * @property OtpPurpose $purpose
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property int $attempt_count
 * @property string|null $request_ip
 * @property Carbon $created_at
 */
class OtpCode extends Model
{
    const UPDATED_AT = null;

    /**
     * Never persisted (see class docblock) — OtpService::request() stashes
     * the plaintext code here purely so a local/debug caller can surface it
     * on screen without a real SMS gateway; unset on every other instance,
     * including this same row once reloaded from the database.
     */
    public ?string $plainCode = null;

    protected $fillable = [
        'user_id',
        'channel',
        'destination',
        'purpose',
        'code_hash',
        'expires_at',
        'verified_at',
        'attempt_count',
        'request_ip',
    ];

    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
