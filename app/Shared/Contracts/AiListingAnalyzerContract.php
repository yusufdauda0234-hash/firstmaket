<?php

namespace App\Shared\Contracts;

/**
 * Swappable-implementation contract (same pattern as SmsSenderContract /
 * PaymentGatewayContract) for the Sprint 9 Listing Review Assistant. Takes a
 * plain snapshot rather than a Product model — Shared must not depend on any
 * feature module (docs/FirstMaket_Developer_Guidelines.md golden rules).
 * Output is always advisory — nothing behind this contract may approve,
 * reject, or otherwise change a product's status; AnalyzeListingJob only
 * ever writes the result to ai_listing_reviews for a human admin to read.
 */
interface AiListingAnalyzerContract
{
    /**
     * @param  array{productId: int, name: string, description: string, priceKobo: int, categoryId: int, imageCount: int}  $listing
     * @return array{status: string, flags: array<int, string>, summary: string, model: string}
     */
    public function analyze(array $listing): array;
}
