<?php

namespace Database\Seeders;

use App\Modules\Settings\Models\Country;
use Illuminate\Database\Seeder;

class CountriesSeeder extends Seeder
{
    public function run(): void
    {
        // Seed Nigeria as the default country
        Country::firstOrCreate(
            ['code' => 'NG'],
            [
                'name' => 'Nigeria',
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
    }
}
