<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalGovernmentController extends Controller
{
    public function byState(Request $request, $stateName): JsonResponse
    {
        $state = State::where('name', $stateName)
            ->where('is_active', true)
            ->first();

        if (!$state) {
            return response()->json(['lgas' => []]);
        }

        $lgas = $state->localGovernments()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('name')
            ->values();

        return response()->json(['lgas' => $lgas]);
    }
}
