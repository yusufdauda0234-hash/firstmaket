<?php

namespace App\Modules\Settings\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class CountryListController
{
    public function index(): JsonResponse
    {
        try {
            // Fetch all countries from REST Countries API
            $response = Http::timeout(10)->get('https://restcountries.com/v3.1/all');

            if ($response->successful()) {
                $countries = collect($response->json())
                    ->map(fn ($country) => [
                        'code' => $country['cca2'] ?? '',
                        'name' => $country['name']['common'] ?? $country['name'] ?? '',
                    ])
                    ->filter(fn ($c) => !empty($c['code']) && !empty($c['name']))
                    ->sortBy('name')
                    ->values()
                    ->all();

                return response()->json(['countries' => $countries]);
            }
        } catch (\Exception $e) {
            // Fallback if API fails
        }

        // Fallback: return empty list if API fails
        return response()->json(['countries' => []], 503);
    }
}
