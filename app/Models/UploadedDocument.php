<?php

namespace App\Models;

use App\Shared\Enums\DocumentStatus;
use App\Shared\Enums\DocumentType;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Private document store shared by vendor onboarding (CAC), identity
 * verification, and admin review — hence a core model rather than a module
 * one. Files live on a non-public disk and are only ever served through
 * permission-checked streamed responses, never public URLs
 * (docs/FirstMaket_Security_Compliance.md).
 */
/**
 * @property int $id
 * @property int $owner_id
 * @property string $owner_type
 * @property DocumentType $document_type
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property DocumentStatus $status
 * @property int|null $uploaded_by
 * @property Carbon $created_at
 */
class UploadedDocument extends Model
{
    use HasUuid;

    const UPDATED_AT = null;

    protected $fillable = [
        'owner_id',
        'owner_type',
        'document_type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'status',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
