<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use App\Shared\Traits\HasUuid;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A vendor listing. Prices are integer kobo and vendor-controlled — admin
 * approves or rejects but never edits the price; an approved product whose
 * price changes drops back to Pending Approval
 * (docs/firstmarket_Implementation_Plan.md Sprint 3).
 */
/**
 * @property int $id
 * @property string $uuid
 * @property int $vendor_id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property int $price_kobo
 * @property int|null $compare_at_price_kobo
 * @property int $stock_quantity
 * @property string|null $rating_average
 * @property int $rating_count
 * @property ProductStatus $status
 * @property string|null $rejection_reason
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $delisted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read VendorProfile $vendor
 * @property-read Category $category
 * @property-read Collection<int, ProductImage> $images
 * @property-read ProductImage|null $primaryImage
 * @property-read User|null $approvedBy
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected $fillable = [
        'vendor_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price_kobo',
        'stock_quantity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_kobo' => 'integer',
            'compare_at_price_kobo' => 'integer',
            'stock_quantity' => 'integer',
            'rating_count' => 'integer',
            'status' => ProductStatus::class,
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'delisted_at' => 'datetime',
        ];
    }

    /**
     * Customer-facing catalog queries must only ever see Approved products.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Approved);
    }

    /** @return BelongsTo<VendorProfile, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(ProductStatusEvent::class);
    }

    /** @return HasMany<ProductPriceHistory, $this> */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    /** @return HasMany<ProductPostingFee, $this> */
    public function postingFees(): HasMany
    {
        return $this->hasMany(ProductPostingFee::class);
    }

    /** @return HasMany<AiListingReview, $this> */
    public function aiReviews(): HasMany
    {
        return $this->hasMany(AiListingReview::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function primaryImageUrl(): ?string
    {
        $image = $this->images->firstWhere('is_primary', true) ?? $this->images->first();

        return $image?->url();
    }
}
