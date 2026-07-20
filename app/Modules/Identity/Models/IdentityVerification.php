<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Shared\Enums\IdentityVerificationStatus;
use App\Shared\Enums\IdentityVerificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property IdentityVerificationType $type
 * @property string $provider
 * @property string|null $provider_reference
 * @property IdentityVerificationStatus $status
 * @property Carbon|null $verified_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class IdentityVerification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'provider',
        'provider_reference',
        'status',
        'verified_at',
        'failure_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => IdentityVerificationType::class,
            'status' => IdentityVerificationStatus::class,
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
