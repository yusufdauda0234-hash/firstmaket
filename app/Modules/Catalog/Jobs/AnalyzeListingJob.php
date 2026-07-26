<?php

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Models\AiListingReview;
use App\Modules\Catalog\Models\Product;
use App\Shared\Contracts\AiListingAnalyzerContract;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sprint 9 Listing Review Assistant: runs whenever a product is submitted
 * for approval (ProductStatusService::submit()) and writes an advisory
 * ai_listing_reviews row for the admin approval queue to display. Never
 * touches the product's status — approval/rejection stays a human decision.
 * If the bound analyzer throws (a real AI provider being unreachable, for
 * example), the failure itself is recorded as a review row so the listing
 * still surfaces for manual review instead of silently having no AI context
 * at all.
 */
class AnalyzeListingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Product $product) {}

    public function handle(AiListingAnalyzerContract $analyzer, AuditLoggerContract $auditLogger): void
    {
        $this->product->loadMissing('images');

        try {
            $result = $analyzer->analyze([
                'productId' => $this->product->id,
                'name' => $this->product->name,
                'description' => $this->product->description,
                'priceKobo' => $this->product->price_kobo,
                'categoryId' => $this->product->category_id,
                'imageCount' => $this->product->images->count(),
            ]);
        } catch (Throwable $exception) {
            $result = [
                'status' => 'error',
                'flags' => [],
                'summary' => 'The AI reviewer failed — this listing needs manual review. ('.$exception->getMessage().')',
                'model' => 'error',
            ];
        }

        $review = AiListingReview::query()->create([
            'product_id' => $this->product->id,
            'status' => $result['status'],
            'flags' => $result['flags'],
            'summary' => $result['summary'],
            'model' => $result['model'],
        ]);

        $auditLogger->log(
            actor: null,
            subject: $review,
            action: 'catalog.ai_listing_reviewed',
            newValues: ['product_id' => $this->product->id, 'status' => $result['status'], 'flag_count' => count($result['flags'])],
        );
    }
}
