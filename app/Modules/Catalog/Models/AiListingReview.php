<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Advisory AI review of a listing (structure ships in Sprint 3; the actual
 * Listing Review Assistant integration is Sprint 8). AI never approves or
 * rejects — human administrators decide.
 */
/**
 * @property int $id
 * @property int $product_id
 * @property string $status
 * @property array<int, string>|null $flags
 * @property string|null $summary
 * @property string|null $model
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Product $product
 */
class AiListingReview extends Model
{
    protected $fillable = [
        'product_id',
        'status',
        'flags',
        'summary',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
