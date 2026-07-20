<?php

namespace App\Modules\Catalog\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VendorFeeSetting;
use App\Modules\Catalog\Requests\StoreProductRequest;
use App\Modules\Catalog\Services\ProductStatusService;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\PostingTier;
use App\Shared\Enums\ProductStatus;
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
 * Vendor-facing listing management (docs/firstmarket_Implementation_Plan.md
 * Sprint 3). Every query is scoped to the authenticated vendor's own
 * profile; only Approved vendors can create or edit listings. Vendors
 * control the price — and changing it on an approved product sends the
 * listing back to Pending Approval.
 */
class VendorProductController extends Controller
{
    public function __construct(private readonly ProductStatusService $statusService) {}

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
            ->withQueryString()
            ->through(fn (Product $product) => [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'category' => $product->category->name,
                'priceKobo' => $product->price_kobo,
                'stockQuantity' => $product->stock_quantity,
                'status' => $product->status->value,
                'rejectionReason' => $product->rejection_reason,
                'imageUrl' => $product->primaryImageUrl(),
                'updatedAt' => $product->updated_at->toDayDateTimeString(),
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
            // For the add/edit modal hosted on this page.
            'categories' => $this->categoryOptions(),
            'feeSettings' => $this->feeSettingsPayload(),
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
     * @return array<string, mixed>
     */
    private function editPayload(Product $product): array
    {
        return [
            'uuid' => $product->uuid,
            'categoryId' => $product->category_id,
            'name' => $product->name,
            'description' => $product->description,
            'priceNaira' => $product->price_kobo / 100,
            'stockQuantity' => $product->stock_quantity,
            'status' => $product->status->value,
            'rejectionReason' => $product->rejection_reason,
            'images' => $product->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'isPrimary' => $image->is_primary,
            ]),
        ];
    }

    public function create(Request $request): Response
    {
        $this->approvedVendorProfile($request);

        return Inertia::render('Vendor/Products/Form', [
            'categories' => $this->categoryOptions(),
            'product' => null,
            'feeSettings' => $this->feeSettingsPayload(),
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
                'price_kobo' => $request->priceKobo(),
                'stock_quantity' => $request->integer('stock_quantity'),
                'status' => ProductStatus::Draft,
            ]);

            $this->storeImages($request, $product);

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
            'product' => [
                'uuid' => $product->uuid,
                'categoryId' => $product->category_id,
                'name' => $product->name,
                'description' => $product->description,
                'priceNaira' => $product->price_kobo / 100,
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
                'price_kobo' => $newPrice,
                'stock_quantity' => $request->integer('stock_quantity'),
            ])->save();

            if ($oldPrice !== $newPrice) {
                $product->priceHistory()->create([
                    'old_price_kobo' => $oldPrice,
                    'new_price_kobo' => $newPrice,
                    'changed_by' => $request->user()->id,
                ]);
            }

            $this->storeImages($request, $product);

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

    public function submit(Request $request, Product $product): RedirectResponse
    {
        $vendor = $this->approvedVendorProfile($request);
        $this->assertOwnedBy($product, $vendor);

        $tier = PostingTier::tryFrom((string) $request->input('tier')) ?? PostingTier::Free;

        $this->statusService->submit($product, $request->user(), $tier);

        return back()->with('success', 'Product submitted for approval.');
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
    private function categoryOptions(): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name]);
    }

    private function uniqueSlug(string $name): string
    {
        return Str::slug($name).'-'.Str::lower(Str::random(6));
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
