<?php

namespace App\Modules\Vendor\Models;

use App\Models\UploadedDocument;
use App\Models\User;
use App\Shared\Casts\Uppercase;
use App\Shared\Enums\VendorStatus;
use App\Shared\Traits\HasUuid;
use Database\Factories\VendorProfileFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $business_name
 * @property string $contact_name
 * @property string|null $address
 * @property VendorStatus $status
 * @property string|null $rejection_reason
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $banned_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read User|null $approvedBy
 * @property-read Collection<int, UploadedDocument> $documents
 */
class VendorProfile extends Model
{
    /** @use HasFactory<VendorProfileFactory> */
    use HasFactory, HasUuid;

    protected static function newFactory(): VendorProfileFactory
    {
        return VendorProfileFactory::new();
    }

    protected $fillable = [
        'user_id',
        'business_name',
        'contact_name',
        'address',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'encrypted',
            'business_name' => Uppercase::class,
            'contact_name' => Uppercase::class,
            'status' => VendorStatus::class,
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'banned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(UploadedDocument::class, 'owner');
    }
}
