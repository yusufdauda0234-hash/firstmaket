<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductStatusService;
use App\Shared\Enums\ProductStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin product approval queue (docs/FirstMaket_Implementation_Plan.md
 * Sprint 3). Reads listing data for display but delegates every state
 * change to the Catalog module's ProductStatusService, which owns the
 * transition rules and domain events. Admins never edit the vendor-set
 * price — approve or reject only.
 */
class ProductApprovalController extends Controller
{
    public function __construct(private readonly ProductStatusService $statusService) {}

    public function index(Request $request): Response
    {
        $status = ProductStatus::tryFrom((string) $request->query('status')) ?? ProductStatus::PendingApproval;

        $products = Product::query()
            ->where('status', $status)
            ->with(['vendor:id,uuid,business_name', 'category:id,name', 'images'])
            ->orderBy('submitted_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'vendor' => $product->vendor->business_name,
                'category' => $product->category->name,
                'priceKobo' => $product->price_kobo,
                'imageUrl' => $product->primaryImageUrl(),
                'submittedAt' => $product->submitted_at?->toDayDateTimeString(),
            ]);

        return Inertia::render('Admin/Products/Index', [
            'products' => $products,
            'status' => $status->value,
        ]);
    }

    public function show(Product $product): Response
    {
        return Inertia::render('Admin/Products/Show', ['product' => $this->payload($product)]);
    }

    /** JSON detail for the quick-view modal opened from the product list. */
    public function details(Product $product): JsonResponse
    {
        return response()->json(['product' => $this->payload($product)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product): array
    {
        $product->load(['vendor:id,uuid,business_name,contact_name', 'category:id,name', 'images', 'priceHistory' => fn ($query) => $query->latest('id')->limit(10), 'statusEvents' => fn ($query) => $query->latest('id')->limit(10), 'aiReviews' => fn ($query) => $query->latest('id')->limit(1), 'postingFees' => fn ($query) => $query->latest('id')->limit(1)]);

        $aiReview = $product->aiReviews->first();
        $postingFee = $product->postingFees->first();

        return [
            'uuid' => $product->uuid,
            'name' => $product->name,
            'description' => $product->description,
            'vendor' => $product->vendor->business_name,
            'category' => $product->category->name,
            'priceKobo' => $product->price_kobo,
            'stockQuantity' => $product->stock_quantity,
            'status' => $product->status->value,
            'rejectionReason' => $product->rejection_reason,
            'submittedAt' => $product->submitted_at?->toDayDateTimeString(),
            'images' => $product->images->map(fn ($image) => ['id' => $image->id, 'url' => $image->url()]),
            'priceHistory' => $product->priceHistory->map(fn ($entry) => [
                'oldPriceKobo' => $entry->old_price_kobo,
                'newPriceKobo' => $entry->new_price_kobo,
                'changedAt' => $entry->created_at->toDayDateTimeString(),
            ]),
            'statusEvents' => $product->statusEvents->map(fn ($event) => [
                'oldStatus' => $event->old_status,
                'newStatus' => $event->new_status,
                'note' => $event->note,
                'changedAt' => $event->created_at->toDayDateTimeString(),
            ]),
            // Advisory only — humans decide; AI population arrives Sprint 9.
            'aiReview' => $aiReview !== null ? [
                'status' => $aiReview->status,
                'flags' => $aiReview->flags ?? [],
                'summary' => $aiReview->summary,
                'model' => $aiReview->model,
                'reviewedAt' => $aiReview->created_at->toDayDateTimeString(),
            ] : null,
            'postingFee' => $postingFee !== null ? [
                'tier' => $postingFee->tier->value,
                'amountKobo' => $postingFee->amount_kobo,
                'paymentStatus' => $postingFee->payment_status,
            ] : null,
        ];
    }

    public function approve(Request $request, Product $product): RedirectResponse
    {
        $this->statusService->approve($product, $request->user());

        return redirect()->route('admin.products.index')->with('success', "\"{$product->name}\" approved.");
    }

    public function reject(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->statusService->reject($product, $request->user(), $validated['reason']);

        return redirect()->route('admin.products.index')->with('success', "\"{$product->name}\" rejected.");
    }
}
