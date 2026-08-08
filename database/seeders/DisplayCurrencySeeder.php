<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\DisplayCurrency;
use Illuminate\Database\Seeder;

/**
 * Starter set of display currencies.
 *
 * The rates below are APPROXIMATE starting points, not a live feed. They are
 * seeded so the picker works out of the box, and the admin screen shows how
 * long ago each was touched so staff can see when one has gone stale. Nothing
 * here affects what a customer is charged — Paystack always settles in NGN,
 * and the naira amount is shown on the pay button.
 *
 * Idempotent on the currency code; an existing rate is never overwritten, so
 * re-running this will not undo a correction made by staff.
 */
class DisplayCurrencySeeder extends Seeder
{
    public function run(): void
    {
        // [code, symbol, name, units per naira, decimals, sort]
        $currencies = [
            ['NGN', '₦', 'Nigerian Naira', '1.0000000000', 0, 0],
            ['USD', '$', 'US Dollar', '0.0006500000', 2, 1],
            ['GBP', '£', 'British Pound', '0.0005100000', 2, 2],
            ['EUR', '€', 'Euro', '0.0006000000', 2, 3],
            ['GHS', 'GH₵', 'Ghanaian Cedi', '0.0068000000', 2, 4],
            ['KES', 'KSh', 'Kenyan Shilling', '0.0840000000', 2, 5],
            ['ZAR', 'R', 'South African Rand', '0.0118000000', 2, 6],
            ['XOF', 'CFA', 'West African CFA Franc', '0.3900000000', 0, 7],
            ['CAD', 'CA$', 'Canadian Dollar', '0.0008900000', 2, 8],
        ];

        foreach ($currencies as [$code, $symbol, $name, $rate, $decimals, $sort]) {
            DisplayCurrency::query()->firstOrCreate(
                ['code' => $code],
                [
                    'symbol' => $symbol,
                    'name' => $name,
                    'units_per_naira' => $rate,
                    'decimals' => $decimals,
                    'is_active' => true,
                    'sort_order' => $sort,
                ],
            );
        }
    }
}
