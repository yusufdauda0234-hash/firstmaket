<?php

namespace Database\Seeders;

use App\Modules\Settings\Models\Country;
use App\Modules\Settings\Models\State;
use App\Modules\Settings\Models\LocalGovernment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ImportCountriesStatesFromJsonSeeder extends Seeder
{
    private $stateMapping = [
        'ng' => [
            'ab' => 'Abia',
            'ad' => 'Adamawa',
            'ak' => 'Akwa Ibom',
            'an' => 'Anambra',
            'ba' => 'Bauchi',
            'by' => 'Bayelsa',
            'be' => 'Benue',
            'bo' => 'Borno',
            'cr' => 'Cross River',
            'de' => 'Delta',
            'eb' => 'Ebonyi',
            'ed' => 'Edo',
            'ek' => 'Ekiti',
            'en' => 'Enugu',
            'fc' => 'FCT - Abuja',
            'go' => 'Gombe',
            'im' => 'Imo',
            'ji' => 'Jigawa',
            'kd' => 'Kaduna',
            'ke' => 'Kano',
            'kt' => 'Katsina',
            'kw' => 'Kebbi',
            'kn' => 'Kogi',
            'ko' => 'Kwara',
            'la' => 'Lagos',
            'na' => 'Nasarawa',
            'ni' => 'Niger',
            'og' => 'Ogun',
            'on' => 'Ondo',
            'os' => 'Osun',
            'oy' => 'Oyo',
            'pl' => 'Plateau',
            'ri' => 'Rivers',
            'so' => 'Sokoto',
            'ta' => 'Taraba',
            'yo' => 'Yobe',
            'za' => 'Zamfara',
        ],
    ];

    public function run(): void
    {
        $jsonPath = base_path('Json-List-of-countries-states-and-cities-in-the-world-main/Json-List-of-countries-states-and-cities-in-the-world-main/json/cites');

        if (!is_dir($jsonPath)) {
            $this->command->warn('JSON data directory not found at: ' . $jsonPath);
            return;
        }

        // For now, focus on Nigeria since it's the primary market
        $this->importCountryData('ng', 'NG', 'Nigeria', $jsonPath);
    }

    private function importCountryData($countryCode, $iso2, $countryName, $jsonPath)
    {
        $country = Country::updateOrCreate(
            ['code' => $iso2],
            ['name' => $countryName, 'is_active' => true, 'sort_order' => 1]
        );

        $countryPath = $jsonPath . '/' . $countryCode;
        if (!is_dir($countryPath)) {
            $this->command->warn("State directory not found: {$countryPath}");
            return;
        }

        $stateFiles = glob($countryPath . '/*.json');
        $stateMapping = $this->stateMapping[$countryCode] ?? [];

        foreach ($stateFiles as $stateFile) {
            $stateCode = pathinfo($stateFile, PATHINFO_FILENAME);
            $stateName = $stateMapping[$stateCode] ?? null;

            if (!$stateName) {
                continue;
            }

            $state = State::updateOrCreate(
                ['country_id' => $country->id, 'name' => $stateName],
                ['is_active' => true, 'sort_order' => 0]
            );

            // Read and parse the JSON file
            $jsonContent = File::get($stateFile);
            $lgasData = json_decode($jsonContent, true);

            if (!$lgasData) {
                continue;
            }

            $sortOrder = 0;
            foreach ($lgasData as $lgaKey => $lgaData) {
                $lgaName = $lgaData['n'] ?? '';

                if (empty($lgaName)) {
                    continue;
                }

                LocalGovernment::updateOrCreate(
                    ['state_id' => $state->id, 'name' => $lgaName],
                    ['is_active' => true, 'sort_order' => $sortOrder++]
                );
            }

            $this->command->line("✓ Imported {$stateName}: " . count($lgasData) . ' LGAs');
        }

        $this->command->info("✓ Imported {$countryName} with all states and LGAs");
    }
}
