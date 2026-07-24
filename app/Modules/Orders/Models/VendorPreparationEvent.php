<?php

namespace App\Modules\Orders\Models;

use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\VendorPreparationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only vendor preparation trail for an order
 * (docs/FirstMaket-Database_Schema.md section 9). `note` carries the
 * rejection reason when status is `rejected`.
 *
 * @property int $id
 * @property int $order_id
 * @property int $vendor_id
 * @property VendorPreparationStatus $status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read Order $order
 * @property-read VendorProfile $vendor
 */
class VendorPreparationEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'vendor_id',
        'status',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VendorPreparationStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<VendorProfile, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }
}
