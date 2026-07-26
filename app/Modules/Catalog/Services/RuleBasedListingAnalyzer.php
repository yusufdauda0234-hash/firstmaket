<?php

namespace App\Modules\Catalog\Services;

use App\Models\Setting;
use App\Modules\Catalog\Models\Product;
use App\Shared\Contracts\AiListingAnalyzerContract;
use App\Shared\Enums\ProductStatus;

/**
 * Default Listing Review Assistant driver (Sprint 9,
 * docs/FirstMaket_Implementation_Plan.md). Runs deterministic, no-external-call
 * checks only — price outliers against the category average, description
 * completeness, image count, and a prohibited/spam keyword scan. Image
 * quality/blur and image-to-product matching genuinely need a vision-capable
 * provider and are left for whichever real driver gets bound to
 * AiListingAnalyzerContract behind services.ai.driver; until then this is
 * the safe, zero-cost, always-available fallback ("AI failure still goes to
 * manual review" — this driver never fails).
 */
class RuleBasedListingAnalyzer implements AiListingAnalyzerContract
{
    private const MODEL_NAME = 'rule-based-v1';

    private const MIN_DESCRIPTION_LENGTH = 40;

    private const MIN_IMAGE_COUNT = 2;

    /** @var list<string> */
    private const PROHIBITED_KEYWORDS = [
        'wire transfer', 'western union', 'whatsapp me', 'call me directly',
        'guaranteed returns', 'get rich', 'no returns accepted', 'cash only',
    ];

    public function analyze(array $listing): array
    {
        $flags = [];

        $priceFlag = $this->priceOutlierFlag($listing);
        if ($priceFlag !== null) {
            $flags[] = $priceFlag;
        }

        if (mb_strlen(trim($listing['description'])) < self::MIN_DESCRIPTION_LENGTH) {
            $flags[] = 'Description is very short — may be incomplete.';
        }

        if ($listing['imageCount'] < self::MIN_IMAGE_COUNT) {
            $flags[] = 'Fewer than '.self::MIN_IMAGE_COUNT.' images — hard for a buyer to judge the item.';
        }

        $keywordFlag = $this->prohibitedKeywordFlag($listing);
        if ($keywordFlag !== null) {
            $flags[] = $keywordFlag;
        }

        return [
            'status' => $flags === [] ? 'clear' : 'flagged',
            'flags' => $flags,
            'summary' => $flags === []
                ? 'No issues detected by the rule-based checks.'
                : count($flags).' potential issue(s) found — review before approving.',
            'model' => self::MODEL_NAME,
        ];
    }

    /**
     * @param  array{productId: int, name: string, description: string, priceKobo: int, categoryId: int, imageCount: int}  $listing
     */
    private function priceOutlierFlag(array $listing): ?string
    {
        $thresholdPercent = (float) Setting::get('ai.price_outlier_threshold_percent', 60);

        $averageKobo = Product::query()
            ->where('category_id', $listing['categoryId'])
            ->where('status', ProductStatus::Approved)
            ->where('id', '!=', $listing['productId'])
            ->avg('price_kobo');

        if ($averageKobo === null || (float) $averageKobo <= 0) {
            return null;
        }

        $deviationPercent = abs($listing['priceKobo'] - $averageKobo) / $averageKobo * 100;

        if ($deviationPercent < $thresholdPercent) {
            return null;
        }

        $direction = $listing['priceKobo'] > $averageKobo ? 'above' : 'below';

        return sprintf(
            'Price is %.0f%% %s the category average (threshold %.0f%%).',
            $deviationPercent,
            $direction,
            $thresholdPercent,
        );
    }

    /**
     * @param  array{productId: int, name: string, description: string, priceKobo: int, categoryId: int, imageCount: int}  $listing
     */
    private function prohibitedKeywordFlag(array $listing): ?string
    {
        $haystack = mb_strtolower($listing['name'].' '.$listing['description']);

        foreach (self::PROHIBITED_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return "Contains a flagged phrase (\"{$keyword}\") — possible spam or off-platform payment request.";
            }
        }

        return null;
    }
}
