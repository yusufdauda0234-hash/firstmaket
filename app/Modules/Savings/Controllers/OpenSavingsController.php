<?php

namespace App\Modules\Savings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Savings\Services\OpenSavingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Open Savings actions: allocate wallet money into the pot. Redirection of
 * the pot into a plan lives on the plan (PlanController@redirectOpenSavings).
 */
class OpenSavingsController extends Controller
{
    public function allocate(Request $request, OpenSavingsService $openSavingsService): RedirectResponse
    {
        $validated = $request->validate([
            'amount_naira' => ['required', 'numeric', 'min:100', 'max:5000000'],
        ]);

        $openSavingsService->allocateFromWallet(
            $request->user(),
            (int) round(((float) $validated['amount_naira']) * 100),
        );

        return back()->with('success', 'Moved to Open Savings.');
    }
}
