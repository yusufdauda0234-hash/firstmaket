<?php

namespace Database\Seeders;

use App\Modules\Settings\Models\Country;
use App\Modules\Settings\Models\State;
use App\Shared\Nigeria;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class NigeriaStatesSeeder extends Seeder
{
    public function run(): void
    {
        $nigeria = Country::where('code', 'NG')->firstOrCreate(
            ['code' => 'NG'],
            ['name' => 'Nigeria', 'is_active' => true, 'sort_order' => 0]
        );

        foreach (Nigeria::STATES as $index => $state) {
            State::firstOrCreate(
                ['country_id' => $nigeria->id, 'name' => $state],
                ['is_active' => true, 'sort_order' => $index]
            );
        }
    }
}
