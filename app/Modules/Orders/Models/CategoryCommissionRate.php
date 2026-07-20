<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Admin-managed commission percentage per category
 * (docs/firstmarket-Database_Schema.md section 9). Append-only history —
 * the active rate is the latest effective_from in the past. Orders snapshot
 * the active rate at creation and are never altered by later changes.
 *
 * @property int $id
 * @property int $category_id
 * @property string $rate_percent
 * @property Carbon $effective_from
 * @property int $set_by
 * @property Carbon|null $created_at
 * @property-read Category $category
 * @property-read User $setBy
 */
class CategoryCommissionRate extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'category_id',
        'rate_percent',
        'effective_from',
        'set_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** The rate in force for a category right now, or null if never set. */
    public static function activeFor(int $categoryId): ?self
    {
        return static::query()
            ->where('category_id', $categoryId)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->first();
    }
}
