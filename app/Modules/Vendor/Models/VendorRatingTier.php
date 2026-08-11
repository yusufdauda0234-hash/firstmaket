<?php

namespace App\Modules\Vendor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-defined vendor performance band.
 *
 * Every threshold is a column rather than a constant, so the business can
 * decide what "Gold" means without a deploy. A null threshold means the tier
 * does not care about that measure at all.
 *
 * @property int $id
 * @property string $name
 * @property string $colour
 * @property int $minimum_score
 * @property int $minimum_delivered_orders
 * @property int|null $maximum_rejection_percent
 * @property int|null $maximum_return_percent
 * @property array<int, string> $benefits
 * @property bool $status
 * @property int $sort_order
 */
class VendorRatingTier extends Model
{
    protected $fillable = [
        'name',
        'colour',
        'minimum_score',
        'minimum_delivered_orders',
        'maximum_rejection_percent',
        'maximum_return_percent',
        'benefits',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'minimum_score' => 'integer',
            'minimum_delivered_orders' => 'integer',
            'maximum_rejection_percent' => 'integer',
            'maximum_return_percent' => 'integer',
            'benefits' => 'array',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Whether a vendor's measured numbers clear every condition of this tier.
     *
     * All conditions must hold — a vendor with a brilliant score but a
     * rejection rate above the ceiling has not earned the tier, which is the
     * whole point of having more than one measure.
     */
    public function isMetBy(int $score, int $deliveredOrders, float $rejectionPercent, float $returnPercent): bool
    {
        if ($score < $this->minimum_score || $deliveredOrders < $this->minimum_delivered_orders) {
            return false;
        }

        if ($this->maximum_rejection_percent !== null && $rejectionPercent > $this->maximum_rejection_percent) {
            return false;
        }

        if ($this->maximum_return_percent !== null && $returnPercent > $this->maximum_return_percent) {
            return false;
        }

        return true;
    }
}
