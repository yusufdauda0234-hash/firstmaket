<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Models\Country;
use App\Modules\Settings\Models\State;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StateController extends Controller
{
    public function byCountry(Country $country): JsonResponse
    {
        $states = $country->states()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('name')
            ->values();

        return response()->json(['states' => $states]);
    }
}
