<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Models\State;
use Illuminate\Http\JsonResponse;

class LocalGovernmentController extends Controller
{
    public function byState(State $state): JsonResponse
    {
        $lgas = $state->localGovernments()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('name')
            ->values();

        return response()->json(['lgas' => $lgas]);
    }
}
