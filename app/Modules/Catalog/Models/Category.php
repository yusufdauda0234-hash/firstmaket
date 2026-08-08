<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Casts\Uppercase;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int|null $parent_id
 * @property string $slug
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Product> $products
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Slug stays lower case — it is part of a URL.
            'name' => Uppercase::class,
        ];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * This category and every ancestor above it, root last.
     *
     * Walked in PHP rather than by recursive SQL: the tree is a handful of
     * levels deep and already in memory on the screens that need it.
     *
     * @return array<int, Category>
     */
    public function ancestry(): array
    {
        $chain = [$this];
        $node = $this;
        // A cycle would only exist if the data were corrupt, but a runaway
        // loop here would hang a page request, so bound it.
        $guard = 0;

        while ($node->parent_id !== null && $guard++ < 10) {
            $node = $node->parent()->first();

            if ($node === null) {
                break;
            }

            $chain[] = $node;
        }

        return $chain;
    }

    /** "Electronics › Phones › Android" — for admin lists and breadcrumbs. */
    public function pathLabel(string $separator = ' › '): string
    {
        return implode($separator, array_reverse(array_map(
            fn (Category $category) => $category->name,
            $this->ancestry(),
        )));
    }

    /** How deep this sits, 0 for a top-level category. */
    public function depth(): int
    {
        return count($this->ancestry()) - 1;
    }

    /**
     * This category's id plus every descendant's.
     *
     * Browsing "Electronics" has to show the phones filed under it, otherwise
     * a parent category looks empty the moment its products are organised
     * into sub-categories.
     *
     * @return array<int, int>
     */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children()->get() as $child) {
            $ids = array_merge($ids, $child->selfAndDescendantIds());
        }

        return $ids;
    }
}
