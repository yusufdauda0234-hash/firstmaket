<?php

namespace App\Modules\Admin\Support;

use App\Shared\Enums\AttributeType;
use App\Shared\Enums\PlanCadence;

/**
 * Ready-made settings an admin can apply in one click.
 *
 * A fresh FirstMaket has nothing configured, and several screens are the
 * difference between a working shop and one that looks broken — no delivery
 * rate means every order ships free, no plan term means Pay Small Small never
 * appears. Asking somebody to invent a sensible weekly instalment schedule
 * before they have sold anything is a poor first hour.
 *
 * Everything here is a starting point, not a recommendation to keep. The
 * figures are deliberately round and obviously editable, and applying a
 * template never overwrites a row that already exists — it only fills gaps,
 * so a second click cannot undo a decision somebody made by hand.
 */
class StarterTemplates
{
    /**
     * Pay Small Small schedules.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function planTerms(): array
    {
        return [
            'starter' => [
                'name' => 'The usual two',
                'summary' => 'Three and six months, monthly. What most customers pick.',
                'rows' => [
                    ['cadence' => PlanCadence::Monthly, 'duration_months' => 3, 'first_payment_due_days' => 7],
                    ['cadence' => PlanCadence::Monthly, 'duration_months' => 6, 'first_payment_due_days' => 7],
                ],
            ],
            'full' => [
                'name' => 'Full range',
                'summary' => 'Weekly for small buys through to a year for big ones.',
                'rows' => [
                    ['cadence' => PlanCadence::Weekly, 'duration_months' => 1, 'first_payment_due_days' => 0],
                    ['cadence' => PlanCadence::Monthly, 'duration_months' => 3, 'first_payment_due_days' => 7],
                    ['cadence' => PlanCadence::Monthly, 'duration_months' => 6, 'first_payment_due_days' => 7],
                    // A year is a long time to hold a price, so this one asks
                    // for a bigger basket before it is offered.
                    ['cadence' => PlanCadence::Monthly, 'duration_months' => 12, 'first_payment_due_days' => 7, 'min_target_kobo' => 100_000_00],
                ],
            ],
            'shortOnly' => [
                'name' => 'Short terms only',
                'summary' => 'Nothing over three months — the least price risk.',
                'rows' => [
                    ['cadence' => PlanCadence::Weekly, 'duration_months' => 1, 'first_payment_due_days' => 0],
                    ['cadence' => PlanCadence::Monthly, 'duration_months' => 2, 'first_payment_due_days' => 3],
                    ['cadence' => PlanCadence::Monthly, 'duration_months' => 3, 'first_payment_due_days' => 7],
                ],
            ],
        ];
    }

    /**
     * Display currencies.
     *
     * Rates are placeholders and will be wrong by the time anyone reads this
     * — the screen says so. Naira is always included because it is the base
     * everything converts from and the only currency Paystack settles.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function currencies(): array
    {
        return [
            'nairaOnly' => [
                'name' => 'Naira only',
                'summary' => 'One currency, no conversion. Simplest to run.',
                'rows' => [
                    ['code' => 'NGN', 'symbol' => '₦', 'name' => 'Nigerian Naira', 'units_per_naira' => '1.000000', 'decimals' => 0],
                ],
            ],
            'international' => [
                'name' => 'Naira plus the majors',
                'summary' => 'For shoppers browsing from abroad. Charged in ₦ either way.',
                'rows' => [
                    ['code' => 'NGN', 'symbol' => '₦', 'name' => 'Nigerian Naira', 'units_per_naira' => '1.000000', 'decimals' => 0],
                    ['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'units_per_naira' => '0.000630', 'decimals' => 2],
                    ['code' => 'GBP', 'symbol' => '£', 'name' => 'British Pound', 'units_per_naira' => '0.000500', 'decimals' => 2],
                    ['code' => 'EUR', 'symbol' => '€', 'name' => 'Euro', 'units_per_naira' => '0.000580', 'decimals' => 2],
                ],
            ],
            'westAfrica' => [
                'name' => 'Naira and West Africa',
                'summary' => 'Neighbouring markets — Ghana and the CFA zone.',
                'rows' => [
                    ['code' => 'NGN', 'symbol' => '₦', 'name' => 'Nigerian Naira', 'units_per_naira' => '1.000000', 'decimals' => 0],
                    ['code' => 'GHS', 'symbol' => 'GH₵', 'name' => 'Ghanaian Cedi', 'units_per_naira' => '0.009500', 'decimals' => 2],
                    ['code' => 'XOF', 'symbol' => 'CFA', 'name' => 'West African CFA', 'units_per_naira' => '0.390000', 'decimals' => 0],
                ],
            ],
        ];
    }

    /**
     * Fields vendors fill in when listing.
     *
     * Scoped to a category where the template names one, so an "Engine size"
     * field does not appear on a listing for shoes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function productFields(): array
    {
        return [
            'universal' => [
                'name' => 'Every listing',
                'summary' => 'Brand, colour and condition — asked on everything.',
                'category' => null,
                'rows' => [
                    ['label' => 'Brand', 'type' => AttributeType::Text, 'is_required' => true],
                    ['label' => 'Colour', 'type' => AttributeType::Text, 'is_required' => false],
                    ['label' => 'Condition', 'type' => AttributeType::Select, 'is_required' => true,
                        'options' => ['Brand new', 'Foreign used', 'Nigerian used', 'Refurbished']],
                ],
            ],
            'electronics' => [
                'name' => 'Electronics',
                'summary' => 'Model, warranty and power — what buyers ask before phoning.',
                'category' => 'Electronics',
                'rows' => [
                    ['label' => 'Model', 'type' => AttributeType::Text, 'is_required' => true],
                    ['label' => 'Warranty', 'type' => AttributeType::Select, 'is_required' => false,
                        'options' => ['No warranty', '3 months', '6 months', '1 year', '2 years']],
                    ['label' => 'Power rating', 'type' => AttributeType::Number, 'unit' => 'W', 'is_required' => false],
                ],
            ],
            'fashion' => [
                'name' => 'Fashion',
                'summary' => 'Size, material and fit. Multi-select, because one listing covers several sizes.',
                'category' => 'Fashion',
                'rows' => [
                    ['label' => 'Size', 'type' => AttributeType::Multiselect, 'is_required' => true,
                        'options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL']],
                    ['label' => 'Material', 'type' => AttributeType::Text, 'is_required' => false],
                    ['label' => 'Gender', 'type' => AttributeType::Select, 'is_required' => false,
                        'options' => ['Men', 'Women', 'Unisex', 'Children']],
                ],
            ],
            'appliances' => [
                'name' => 'Home appliances',
                'summary' => 'Capacity and power, so a buyer knows it fits and runs.',
                'category' => 'Home & Kitchen',
                'rows' => [
                    ['label' => 'Capacity', 'type' => AttributeType::Number, 'unit' => 'L', 'is_required' => false],
                    ['label' => 'Energy rating', 'type' => AttributeType::Select, 'is_required' => false,
                        'options' => ['A++', 'A+', 'A', 'B', 'C']],
                    ['label' => 'Inverter', 'type' => AttributeType::Boolean, 'is_required' => false],
                ],
            ],
        ];
    }

    /**
     * Delivery rates, in kobo.
     *
     * The default row — the one with no state — is what everywhere without
     * its own price pays. Every template includes it, because without one
     * those orders ship free.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function deliveryRates(): array
    {
        return [
            'flat' => [
                'name' => 'One price nationwide',
                'summary' => '₦1,500 everywhere. Easiest to explain to a customer.',
                'rows' => [
                    ['state' => null, 'fee_kobo' => 150_000, 'free_threshold_kobo' => 0],
                ],
            ],
            'zoned' => [
                'name' => 'Cheaper in the big cities',
                'summary' => 'Lagos, Abuja and Rivers under the national price; everywhere else pays it.',
                'rows' => [
                    ['state' => null, 'fee_kobo' => 200_000, 'free_threshold_kobo' => 0],
                    ['state' => 'Lagos', 'fee_kobo' => 100_000, 'free_threshold_kobo' => 0],
                    ['state' => 'FCT', 'fee_kobo' => 120_000, 'free_threshold_kobo' => 0],
                    ['state' => 'Rivers', 'fee_kobo' => 130_000, 'free_threshold_kobo' => 0],
                ],
            ],
            'freeOver' => [
                'name' => 'Free over ₦50,000',
                'summary' => '₦1,500 normally, nothing on a big enough basket.',
                'rows' => [
                    ['state' => null, 'fee_kobo' => 150_000, 'free_threshold_kobo' => 50_000_00],
                ],
            ],
        ];
    }

    /**
     * Promo codes.
     *
     * Every percentage code carries a ceiling, because 10% off an unbounded
     * basket is not a promotion — it is an open cheque.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function promoCodes(): array
    {
        return [
            'welcome' => [
                'name' => 'Welcome offer',
                'summary' => 'WELCOME10 — 10% off a first order, up to ₦5,000.',
                'rows' => [
                    ['code' => 'WELCOME10', 'description' => 'First order discount', 'type' => 'percent',
                        'percent_off' => '10.00', 'max_discount_kobo' => 5_000_00, 'first_order_only' => true],
                ],
            ],
            'freeDelivery' => [
                'name' => 'Free delivery',
                'summary' => 'FREESHIP — covers the delivery fee on orders over ₦20,000.',
                'rows' => [
                    ['code' => 'FREESHIP', 'description' => 'Delivery on us', 'type' => 'free_delivery',
                        'min_order_kobo' => 20_000_00],
                ],
            ],
            'fixed' => [
                'name' => 'Fixed amount off',
                'summary' => 'SAVE1000 — ₦1,000 off orders over ₦10,000.',
                'rows' => [
                    ['code' => 'SAVE1000', 'description' => 'Flat discount', 'type' => 'fixed',
                        'amount_off_kobo' => 1_000_00, 'min_order_kobo' => 10_000_00],
                ],
            ],
            'flash' => [
                'name' => 'Flash sale',
                'summary' => 'FLASH15 — 15% off, capped at ₦10,000, first 100 customers.',
                'rows' => [
                    ['code' => 'FLASH15', 'description' => 'Limited-run flash sale', 'type' => 'percent',
                        'percent_off' => '15.00', 'max_discount_kobo' => 10_000_00, 'max_redemptions' => 100],
                ],
            ],
        ];
    }

    /**
     * Home page hero carousel slides.
     *
     * offer_value is left null for 'from_price' and 'campaign_discount' rows
     * on purpose — those figures are computed from real catalog/campaign
     * data when the page renders, never typed in here. A hero claiming "60%
     * OFF" with nothing behind it is exactly what these templates replace.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function heroSlides(): array
    {
        return [
            'classic' => [
                'name' => 'Classic three',
                'summary' => 'Deals, flash sale (once a campaign is live), and a seller pitch.',
                'rows' => [
                    [
                        'eyebrow' => '🔥 Super Deals', 'title' => 'Grab trusted deals across Nigeria.',
                        'description' => 'Verified vendors, locked prices, fast delivery nationwide.',
                        'cta_label' => 'Grab It Now →', 'cta_target' => 'auth_gate', 'theme' => 'brand',
                        'emoji' => '🛍️', 'offer_type' => 'from_price', 'offer_label' => 'Starting from',
                        'offer_value' => null, 'sort_order' => 1,
                    ],
                    [
                        'eyebrow' => '⚡ Flash Sale', 'title' => 'Electronics & appliances, priced to move.',
                        'description' => 'Limited-time prices from verified Nigerian sellers.',
                        'cta_label' => 'View Deals →', 'cta_target' => 'catalog', 'theme' => 'brand-reverse',
                        'emoji' => '📺', 'offer_type' => 'campaign_discount', 'offer_label' => 'Up to',
                        'offer_value' => null, 'sort_order' => 2,
                    ],
                    [
                        'eyebrow' => '🏪 Sell with Us', 'title' => 'Launch your storefront on FirstMaket.',
                        'description' => 'Zero listing fees, instant Paystack payouts, verified buyers.',
                        'cta_label' => 'Start Selling →', 'cta_target' => 'vendor_register', 'theme' => 'brand-deep',
                        'emoji' => '🚀', 'offer_type' => 'static', 'offer_label' => 'Sellers pay',
                        'offer_value' => '₦0 fees', 'sort_order' => 3,
                    ],
                ],
            ],
            'minimal' => [
                'name' => 'Two slides',
                'summary' => 'Deals and a seller pitch — no flash-sale slide until a campaign is running.',
                'rows' => [
                    [
                        'eyebrow' => '🔥 Super Deals', 'title' => 'Grab trusted deals across Nigeria.',
                        'description' => 'Verified vendors, locked prices, fast delivery nationwide.',
                        'cta_label' => 'Grab It Now →', 'cta_target' => 'auth_gate', 'theme' => 'brand',
                        'emoji' => '🛍️', 'offer_type' => 'from_price', 'offer_label' => 'Starting from',
                        'offer_value' => null, 'sort_order' => 1,
                    ],
                    [
                        'eyebrow' => '🏪 Sell with Us', 'title' => 'Launch your storefront on FirstMaket.',
                        'description' => 'Zero listing fees, instant Paystack payouts, verified buyers.',
                        'cta_label' => 'Start Selling →', 'cta_target' => 'vendor_register', 'theme' => 'brand-deep',
                        'emoji' => '🚀', 'offer_type' => 'static', 'offer_label' => 'Sellers pay',
                        'offer_value' => '₦0 fees', 'sort_order' => 2,
                    ],
                ],
            ],
        ];
    }

    /**
     * The list a page shows, without the rows themselves.
     *
     * @param  array<string, array<string, mixed>>  $templates
     * @return array<int, array<string, mixed>>
     */
    public static function forDisplay(array $templates): array
    {
        return collect($templates)
            ->map(fn (array $template, string $key) => [
                'key' => $key,
                'name' => $template['name'],
                'summary' => $template['summary'],
                'count' => count($template['rows']),
            ])
            ->values()
            ->all();
    }
}
