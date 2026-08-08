<?php

namespace App\Modules\Orders\Services;

use App\Models\Setting;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\CommissionRule;

/**
 * What FirstMaket takes on one sale, which rule decided it, and why.
 *
 * Rules are resolved most-specific-first — product, then vendor, then
 * category, then everything — and within a scope, the band containing the
 * unit price wins. The first match is used; nothing is combined, so a rate is
 * always traceable to exactly one row an admin can open.
 *
 * Falls back to the platform default when no rule matches, so an empty table
 * charges what it always did rather than nothing.
 */
readonly class CommissionRate
{
    public function __construct(
        public float $percent,
        /** rule | default (vendor_deal on orders placed before rules absorbed them) */
        public string $source,
        public ?string $sourceLabel = null,
        public ?CommissionRule $rule = null,
    ) {}

    /** The rate that applies to selling $product at $unitPriceKobo today. */
    public static function for(Product $product, ?int $unitPriceKobo = null): self
    {
        $price = $unitPriceKobo ?? $product->price_kobo;

        $rule = self::matchingRule($product, $price);

        if ($rule !== null) {
            return new self(
                percent: (float) $rule->rate_percent,
                source: 'rule',
                sourceLabel: $rule->scopeLabel(),
                rule: $rule,
            );
        }

        return new self(
            percent: (float) Setting::get('orders.default_commission_percent', 0),
            source: 'default',
        );
    }

    /**
     * The most specific active rule whose band contains this price.
     *
     * Loaded in one query and ranked in PHP: the candidate set is tiny (rules
     * touching one product), and expressing "most specific, then narrowest
     * band" in SQL would be harder to read than it is worth.
     */
    private static function matchingRule(Product $product, int $priceKobo): ?CommissionRule
    {
        return CommissionRule::query()
            ->active()
            ->where(function ($query) use ($product) {
                $query->where('scope_type', 'global')
                    ->orWhere(fn ($q) => $q->where('scope_type', 'category')->where('scope_id', $product->category_id))
                    ->orWhere(fn ($q) => $q->where('scope_type', 'vendor')->where('scope_id', $product->vendor_id))
                    ->orWhere(fn ($q) => $q->where('scope_type', 'product')->where('scope_id', $product->id));
            })
            ->get()
            ->filter(fn (CommissionRule $rule) => $rule->coversPrice($priceKobo))
            ->sortByDesc(fn (CommissionRule $rule) => [
                $rule->specificity(),
                // A narrower band is a deliberately tighter rule, so it wins
                // over a catch-all in the same scope.
                $rule->max_price_kobo === null ? 0 : 1,
                $rule->min_price_kobo,
            ])
            ->first();
    }

    /** Commission on one unit, honouring the matched rule's fee and caps. */
    public function onKobo(int $unitPriceKobo): int
    {
        if ($this->rule !== null) {
            return $this->rule->commissionOn($unitPriceKobo);
        }

        return (int) round($unitPriceKobo * $this->percent / 100);
    }

    /** Plain English for the admin order screen. */
    public function explain(): string
    {
        return match ($this->source) {
            // Only on orders placed before vendor deals became rules.
            'vendor_deal' => 'Rate agreed with '.($this->sourceLabel ?? 'this vendor'),
            'rule' => $this->ruleExplanation(),
            default => 'Platform default — no rule matched this sale',
        };
    }

    private function ruleExplanation(): string
    {
        $rule = $this->rule;

        if ($rule === null) {
            return 'Commission rule';
        }

        $band = $rule->max_price_kobo === null
            ? ($rule->min_price_kobo > 0 ? ' above ₦'.number_format($rule->min_price_kobo / 100) : '')
            : ' between ₦'.number_format($rule->min_price_kobo / 100)
                .' and ₦'.number_format($rule->max_price_kobo / 100);

        return match ($rule->scope_type) {
            'product' => 'Rule for this product'.$band,
            'vendor' => 'Rule for '.$rule->scopeLabel().$band,
            'category' => $rule->scopeLabel().' rule'.$band,
            default => 'Marketplace-wide rule'.$band,
        };
    }
}
