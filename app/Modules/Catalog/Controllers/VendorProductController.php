<?php

namespace App\Modules\Catalog\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\DisplayCurrency;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\WishlistPriceAlert;
use App\Modules\Customer\Notifications\WishlistPriceDropNotification;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\VendorFeeSetting;
use App\Modules\Catalog\Requests\StoreProductRequest;
use App\Modules\Catalog\Services\ProductAttributeService;
use App\Modules\Catalog\Services\ProductStatusService;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PostingTier;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\SavingsGoalStatus;
use App\Shared\Enums\VendorStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Vendor-facing listing management (docs/FirstMaket_Implementation_Plan.md
 * Sprint 3). Every query is scoped to the authenticated vendor's own
 * profile; only Approved vendors can create or edit listings. Vendors
 * control the price — and changing it on an approved product sends the
 * listing back to Pending Approval.
 */
class VendorProductController extends Controller
{
    public function __construct(
        private readonly ProductStatusService $statusService,
        private readonly ProductAttributeService $attributes,
    ) {}

    /**
     * Every category's field list, keyed by category id.
     *
     * Sent whole rather than per-category so changing the category dropdown
     * re-renders the form instantly instead of waiting on a request — the
     * catalogue is small and the payload is only field definitions.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function attributeFieldsByCategory(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (Category $category) => [
                $category->id => $this->attributes->forCategory($category)
                    ->map(fn ($attribute) => $attribute->toFormField())
                    ->all(),
            ])
            ->all();
    }

    public function index(Request $request): Response
    {
        $vendor = $this->approvedVendorProfile($request);

        $status = ProductStatus::tryFrom((string) $request->query('status'));

        $products = Product::query()
            ->where('vendor_id', $vendor->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['category:id,name', 'images'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // One pass over the page's ids rather than two queries per row.
        $committed = $this->productIdsWithOpenCommitments(
            collect($products->items())->pluck('id')->all(),
        );

        $products->through(fn (Product $product) => [
            'uuid' => $product->uuid,
            'name' => $product->name,
            'category' => $product->category->name,
            'priceKobo' => $product->price_kobo,
            'stockQuantity' => $product->stock_quantity,
            'status' => $product->status->value,
            'rejectionReason' => $product->rejection_reason,
            'imageUrl' => $product->primaryImageUrl(),
            'updatedAt' => $product->updated_at->toDayDateTimeString(),
            // Only an approved listing exists on the storefront; anything
            // else has no public page to open yet.
            'viewUrl' => $product->status === ProductStatus::Approved
                ? $this->storefrontUrl('product/'.$product->slug)
                : null,
            'canDelete' => ! in_array($product->id, $committed, true),
        ]);

        $counts = Product::query()
            ->where('vendor_id', $vendor->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Vendor/Products/Index', [
            'products' => $products,
            'counts' => $counts,
            'activeStatus' => $status?->value,
            // For the add/edit modal hosted on this page. It is the form
            // vendors actually use — the standalone page is reached far less
            // often — so it needs everything that one does: the catalogue
            // tree, the admin-defined fields, and the built-in wording.
            'categories' => $this->categoryOptions(),
            'feeSettings' => $this->feeSettingsPayload(),
            'attributeFieldsByCategory' => $this->attributeFieldsByCategory(),
            'builtInFields' => $this->builtInFieldLabels(),
        ]);
    }

    /** JSON edit payload for the product modal opened from the list. */
    public function details(Request $request, Product $product): JsonResponse
    {
        $vendor = $this->approvedVendorProfile($request);
        $this->assertOwnedBy($product, $vendor);

        return response()->json(['product' => $this->editPayload($product)]);
    }

    /**
     * The link as the vendor typed it, or null.
     *
     * ConvertEmptyStringsToNull already turns a cleared box into null, but a
     * vendor who leaves a space behind would otherwise store " " and fail the
     * next edit on a link they cannot see.
     */
    private function videoUrl(StoreProductRequest $request): ?string
    {
        $url = trim((string) $request->input('video_url'));

        return $url === '' ? null : $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function editPayload(Product $product): array
    {
        return [
            'uuid' => $product->uuid,
            'categoryId' => $product->category_id,
            'name' => $product->name,
            'description' => $product->description,
            'videoUrl' => $product->video_url,
            'priceNaira' => $product->price_kobo / 100,
            'compareAtNaira' => $product->compare_at_price_kobo === null
                ? null
                : $product->compare_at_price_kobo / 100,
            'stockQuantity' => $product->stock_quantity,
            'status' => $product->status->value,
            'rejectionReason' => $product->rejection_reason,
            'images' => $product->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'isPrimary' => $image->is_primary,
            ]),
            // The answers to the admin-defined fields. Without these, opening
            // a listing in the modal and saving it would blank everything a
            // vendor had filled in for its category.
            'attributes' => (object) $this->attributes->valuesFor($product),
        ];
    }

    public function create(Request $request): Response
    {
        $this->approvedVendorProfile($request);

        return Inertia::render('Vendor/Products/Form', [
            'categories' => $this->categoryOptions(),
            'product' => null,
            'feeSettings' => $this->feeSettingsPayload(),
            'attributeFieldsByCategory' => $this->attributeFieldsByCategory(),
            'builtInFields' => $this->builtInFieldLabels(),
            'attributeValues' => (object) [],
            'currencies' => DisplayCurrency::active()->map(fn ($c) => [
                'code' => $c->code,
                'symbol' => $c->symbol,
                'name' => $c->name,
            ])->all(),
        ]);
    }

    public function store(StoreProductRequest $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $vendor = $this->approvedVendorProfile($request);

        $product = DB::transaction(function () use ($request, $vendor) {
            $product = Product::query()->create([
                'vendor_id' => $vendor->id,
                'category_id' => $request->integer('category_id'),
                'name' => $request->string('name'),
                'slug' => $this->uniqueSlug($request->string('name')->value()),
                'description' => $request->string('description'),
                'video_url' => $this->videoUrl($request),
                'price_kobo' => $request->priceKobo(),
                'compare_at_price_kobo' => $request->compareAtKobo(),
                'stock_quantity' => $request->integer('stock_quantity'),
                'status' => ProductStatus::Draft,
            ]);

            $this->storeImages($request, $product);
            $this->attributes->sync($product, $request->attributeValues());

            return $product;
        });

        $auditLogger->log(actor: $request->user(), subject: $product, action: 'catalog.product_created');

        if ($request->boolean('submit')) {
            $this->statusService->submit($product, $request->user(), $request->tier());

            return redirect()->route('vendor.products.index')->with('success', 'Product submitted for approval.');
        }

        return redirect()->route('vendor.products.index')->with('success', 'Draft saved.');
    }

    public function edit(Request $request, Product $product): Response
    {
        $vendor = $this->approvedVendorProfile($request);
        $this->assertOwnedBy($product, $vendor);

        return Inertia::render('Vendor/Products/Form', [
            'categories' => $this->categoryOptions(),
            'feeSettings' => $this->feeSettingsPayload(),
            'attributeFieldsByCategory' => $this->attributeFieldsByCategory(),
            'builtInFields' => $this->builtInFieldLabels(),
            'attributeValues' => (object) $this->attributes->valuesFor($product),
            'currencies' => DisplayCurrency::active()->map(fn ($c) => [
                'code' => $c->code,
                'symbol' => $c->symbol,
                'name' => $c->name,
            ])->all(),
            'product' => [
                'uuid' => $product->uuid,
                'categoryId' => $product->category_id,
                'name' => $product->name,
                'description' => $product->description,
                'videoUrl' => $product->video_url,
                'priceNaira' => $product->price_kobo / 100,
                'compareAtNaira' => $product->compare_at_price_kobo === null
                    ? null
                    : $product->compare_at_price_kobo / 100,
                'stockQuantity' => $product->stock_quantity,
                'status' => $product->status->value,
                'rejectionReason' => $product->rejection_reason,
                'images' => $product->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->url(),
                    'isPrimary' => $image->is_primary,
                ]),
            ],
        ]);
    }

    public function update(StoreProductRequest $request, Product $product): RedirectResponse
    {
        $vendor = $this->approvedVendorProfile($request);
        $this->assertOwnedBy($product, $vendor);

        $wasApproved = $product->status === ProductStatus::Approved;
        $oldPrice = $product->price_kobo;
        $newPrice = $request->priceKobo();

        DB::transaction(function () use ($request, $product, $oldPrice, $newPrice, $wasApproved) {
            $product->fill([
                'category_id' => $request->integer('category_id'),
                'name' => $request->string('name'),
                'description' => $request->string('description'),
                'video_url' => $this->videoUrl($request),
                'price_kobo' => $newPrice,
                'compare_at_price_kobo' => $request->compareAtKobo(),
                'stock_quantity' => $request->integer('stock_quantity'),
            ])->save();

            if ($oldPrice !== $newPrice) {
                $product->priceHistory()->create([
                    'old_price_kobo' => $oldPrice,
                    'new_price_kobo' => $newPrice,
                    'changed_by' => $request->user()->id,
                ]);

                if ($newPrice < $oldPrice) {
                    WishlistPriceAlert::query()
                        ->where('product_id', $product->id)
                        ->where(function ($query) use ($newPrice) {
                            $query->whereNull('last_notified_price_kobo')
                                ->orWhere('last_notified_price_kobo', '!=', $newPrice);
                        })
                        ->get()
                        ->each(function (WishlistPriceAlert $alert) use ($product, $oldPrice, $newPrice): void {
                            $dropPercent = (int) round((1 - ($newPrice / $oldPrice)) * 100);

                            if ($dropPercent < $alert->threshold_percent) {
                                return;
                            }

                            $alert->update(['last_notified_price_kobo' => $newPrice]);
                            $alert->user->notify(new WishlistPriceDropNotification($product, $oldPrice, $newPrice));
                        });
                }
            }

            $this->storeImages($request, $product);
            // Runs after the category is saved, so switching category swaps
            // the product onto that category's fields and clears answers to
            // fields that no longer apply.
            $this->attributes->sync($product->refresh(), $request->attributeValues());

            // An approved listing whose price moved must be re-reviewed
            // before customers can see the new price.
            if ($wasApproved && $oldPrice !== $newPrice) {
                $this->statusService->returnToPendingAfterPriceChange($product, $request->user());
            }
        });

        if ($request->boolean('submit') && in_array($product->refresh()->status, [ProductStatus::Draft, ProductStatus::Rejected], true)) {
            $this->statusService->submit($product, $request->user(), $request->tier());

            return redirect()->route('vendor.products.index')->with('success', 'Product submitted for approval.');
        }

        return redirect()->route('vendor.products.index')->with('success', 'Product updated.');
    }

    /**
     * Submit several drafts for approval at once.
     *
     * A vendor listing a batch of stock writes them all as drafts, then sends
     * them together. Each still goes through ProductStatusService, so the
     * posting fee, status rules and audit entry are identical to submitting one
     * by hand — and anything not currently a draft is skipped rather than
     * failing the batch.
     */
    public function bulkSubmit(Request $request): RedirectResponse
    {
        $vendor = $this->approvedVendorProfile($request);

        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid'],
            'tier' => ['nullable', 'string'],
        ], [
            'uuids.required' => 'Select at least one listing first.',
        ]);

        $tier = PostingTier::tryFrom((string) $request->input('tier')) ?? PostingTier::Free;

        // Scoped to this vendor's own products, so a forged uuid matches
        // nothing rather than touching somebody else's listing.
        $products = Product::query()
            ->where('vendor_id', $vendor->id)
            ->whereIn('uuid', $validated['uuids'])
            ->get();

        $done = 0;
        $skipped = 0;

        foreach ($products as $product) {
            try {
                $this->statusService->submit($product, $request->user(), $tier);
                $done++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $message = "{$done} listing".($done === 1 ? '' : 's').' submitted for approval.';

        if ($skipped > 0) {
            $message .= " {$skipped} skipped — already submitted or not a draft.";
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    public function submit(Request $request, Product $product): RedirectResponse
    {
        $vendor = $this->approvedVendorProfile($request);
        $this->assertOwnedBy($product, $vendor);

        $tier = PostingTier::tryFrom((string) $request->input('tier')) ?? PostingTier::Free;

        $this->statusService->submit($product, $request->user(), $tier);

        return back()->with('success', 'Product submitted for approval.');
    }

    /**
     * Remove a listing. Soft delete, so the slug stays taken and anything
     * that already references the product keeps resolving.
     *
     * A listing that is live or that customers are already paying towards
     * cannot be deleted — delisting is the honest move there, because
     * deleting would pull the item out from under an open plan.
     */
    public function destroy(Request $request, Product $product, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $vendor = $this->approvedVendorProfile($request);
        $this->assertOwnedBy($product, $vendor);

        if ($this->hasOpenCommitments($product)) {
            return back()->with(
                'error',
                'Customers are already buying or saving towards this listing. Delist it instead of deleting.',
            );
        }

        $status = $product->status->value;
        $product->delete();

        $auditLogger->log(
            actor: $request->user(),
            subject: $product,
            action: 'catalog.product_deleted',
            oldValues: ['status' => $status],
        );

        return back()->with('success', 'Listing deleted.');
    }

    /** Any order still in flight, or any plan still being paid off. */
    private function hasOpenCommitments(Product $product): bool
    {
        return $this->productIdsWithOpenCommitments([$product->id]) !== [];
    }

    /**
     * Which of these products a customer is mid-transaction on — an order
     * that has not settled, or a plan still being paid off.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    private function productIdsWithOpenCommitments(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $settled = [
            OrderStatus::Delivered->value,
            OrderStatus::Cancelled->value,
            OrderStatus::VendorRejected->value,
        ];

        $fromOrders = DB::table('orders')
            ->whereIn('product_id', $productIds)
            ->whereNotIn('status', $settled)
            ->pluck('product_id');

        $fromPlans = DB::table('savings_goal_items')
            ->join('savings_goals', 'savings_goals.id', '=', 'savings_goal_items.savings_goal_id')
            ->whereIn('savings_goal_items.product_id', $productIds)
            ->where('savings_goals.status', SavingsGoalStatus::Saving->value)
            ->pluck('savings_goal_items.product_id');

        return $fromOrders->merge($fromPlans)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * An absolute URL on the customer-facing storefront. Built from config
     * rather than route(), which would resolve against the vendor subdomain
     * this controller answers on.
     */
    private function storefrontUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function approvedVendorProfile(Request $request): VendorProfile
    {
        $profile = $request->user()?->vendorProfile;

        if ($profile === null || $profile->status !== VendorStatus::Approved) {
            throw new AccessDeniedHttpException('Only approved vendors can manage product listings.');
        }

        return $profile;
    }

    private function assertOwnedBy(Product $product, VendorProfile $vendor): void
    {
        if ($product->vendor_id !== $vendor->id) {
            throw new AccessDeniedHttpException('This listing belongs to another vendor.');
        }
    }

    /**
     * Posting-fee context for the listing form: mode plus per-tier prices in
     * naira, so the vendor picks a tier with the cost in front of them.
     *
     * @return array{postingMode: string, basicFeeNaira: float, premiumFeeNaira: float, featuredFeeNaira: float}
     */
    private function feeSettingsPayload(): array
    {
        $settings = VendorFeeSetting::current();

        return [
            'postingMode' => $settings->posting_mode,
            'basicFeeNaira' => $settings->basic_fee_kobo / 100,
            'premiumFeeNaira' => $settings->premium_fee_kobo / 100,
            'featuredFeeNaira' => $settings->featured_fee_kobo / 100,
        ];
    }

    /** @return Collection<int, array{id: int, name: string}> */
    /**
     * Wording for the fields every product has, keyed by system key.
     *
     * The vendor form used to hardcode "Product name", "Price (₦)" and the
     * rest, which made the admin field manager a lie: staff could reword a
     * built-in and nothing changed on the form it claimed to describe. The
     * labels now come from those rows, so the screen and the form cannot
     * disagree.
     *
     * Falls back to the hardcoded wording per field on the client, so a
     * missing row shows the right label rather than an empty one.
     *
     * @return array<string, array<string, mixed>>
     */
    private function builtInFieldLabels(): array
    {
        return ProductAttribute::query()
            ->builtIn()
            ->get(['system_key', 'label', 'help_text', 'is_required'])
            ->mapWithKeys(fn (ProductAttribute $attribute) => [
                $attribute->system_key => [
                    'label' => $attribute->label,
                    'helpText' => $attribute->help_text,
                    'isRequired' => $attribute->is_required,
                ],
            ])
            ->all();
    }

    /**
     * The catalogue as a tree, so the form can ask for a category and then a
     * sub-category.
     *
     * It used to be a flat list of every active row, which put "Electronics"
     * and "Smartphones" side by side as if they were alternatives and let a
     * vendor file a phone under the parent. Everything then sat in the broad
     * bucket, and browsing Electronics > Smartphones found nothing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function categoryOptions(): Collection
    {
        $active = Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $byParent = $active->groupBy('parent_id');

        return $active
            ->whereNull('parent_id')
            ->map(fn (Category $parent) => [
                'id' => $parent->id,
                'name' => $parent->name,
                'children' => $byParent->get($parent->id, collect())
                    ->map(fn (Category $child) => ['id' => $child->id, 'name' => $child->name])
                    ->values()
                    ->all(),
            ])
            ->values();
    }

    /**
     * The product's public URL, so it has to read like the product: "fridge",
     * not "fridge-owgzrq". A suffix is only added when the clean slug is
     * genuinely taken, and then it counts up rather than being random noise —
     * products_slug_unique is what ultimately guarantees uniqueness, this
     * just finds a free one first.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        // A name of nothing but punctuation or non-Latin script slugs to an
        // empty string, which would collide with itself forever.
        if ($base === '') {
            $base = 'product';
        }

        $slug = $base;

        for ($suffix = 2; $this->slugTaken($slug); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /** Soft-deleted products keep their slug, so they still count as taken. */
    private function slugTaken(string $slug): bool
    {
        return Product::query()->withTrashed()->where('slug', $slug)->exists();
    }

    private function storeImages(StoreProductRequest $request, Product $product): void
    {
        foreach ($request->file('images', []) as $index => $file) {
            $path = $file->store('products', 'public');

            $product->images()->create([
                'path' => $path,
                'sort_order' => $product->images()->count(),
                'is_primary' => $product->images()->count() === 0,
            ]);
        }
    }
}
