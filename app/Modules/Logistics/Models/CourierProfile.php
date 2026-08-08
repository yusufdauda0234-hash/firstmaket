<?php

namespace App\Modules\Logistics\Models;

use App\Models\User;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\VehicleType;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a courier is, beyond a user row with a role.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property VehicleType $vehicle_type
 * @property string|null $vehicle_plate
 * @property string|null $base_state
 * @property string|null $base_lga
 * @property int $max_open_shipments Zero means no ceiling.
 * @property string|null $carrier Null = FirstMaket's own courier.
 * @property bool $is_available
 * @property-read User $user
 */
class CourierProfile extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'vehicle_type',
        'vehicle_plate',
        'base_state',
        'base_lga',
        'max_open_shipments',
        'max_float_kobo',
        'carrier',
        'is_available',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleType::class,
            'max_open_shipments' => 'integer',
            'max_float_kobo' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true);
    }

    /**
     * How many shipments this courier is currently holding.
     *
     * The number a dispatcher actually needs: not how many they have ever
     * carried, but how much is on the van right now.
     */
    public function openShipmentCount(): int
    {
        return DeliveryAssignment::query()
            ->where('logistics_user_id', $this->user_id)
            ->where('status', DeliveryAssignmentStatus::Assigned)
            ->count();
    }

    /**
     * Advisory, not enforced.
     *
     * A ceiling is a default for a normal day; a dispatcher covering for
     * somebody who called in sick has to be able to go past it. So this
     * warns on the dispatch screen and nothing refuses the assignment.
     */
    public function isOverloaded(): bool
    {
        return $this->max_open_shipments > 0
            && $this->openShipmentCount() >= $this->max_open_shipments;
    }
}
