<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Raw webhook event log for replay protection and debugging
 * (docs/FirstMaket-Database_Schema.md section 7). Every inbound webhook is
 * recorded here — valid or not — before any balance is touched.
 *
 * @property int $id
 * @property string|null $event
 * @property string|null $paystack_reference
 * @property bool $signature_valid
 * @property string|null $payload_hash
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $processed_at
 * @property string $processing_status
 * @property string|null $error_message
 * @property Carbon $created_at
 */
class PaystackWebhookEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'event',
        'paystack_reference',
        'signature_valid',
        'payload_hash',
        'payload',
        'processed_at',
        'processing_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
