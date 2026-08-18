<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Models\State;
use App\Modules\Settings\Models\LocalGovernment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LocalGovernmentsSettingsController extends Controller
{
    public function index(State $state): Response
    {
        return Inertia::render('Admin/Settings/LocalGovernments', [
            'state' => [
                'id' => $state->id,
                'name' => $state->name,
                'code' => $state->code,
                'countryId' => $state->country_id,
                'countryName' => $state->country->name,
            ],
            'lgas' => $state->localGovernments()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (LocalGovernment $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'code' => $l->code,
                    'isActive' => $l->is_active,
                    'sortOrder' => $l->sort_order,
                ])
                ->all(),
        ]);
    }

    public function store(Request $request, State $state): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);

        LocalGovernment::create([
            'state_id' => $state->id,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'is_active' => true,
            'sort_order' => LocalGovernment::where('state_id', $state->id)->max('sort_order') + 1,
        ]);

        return redirect()->back()->with('success', 'LGA added successfully.');
    }

    public function toggle(State $state, LocalGovernment $lga): RedirectResponse
    {
        $lga->update(['is_active' => !$lga->is_active]);

        return redirect()->back()->with('success', 'LGA updated.');
    }

    public function destroy(State $state, LocalGovernment $lga): RedirectResponse
    {
        $lga->delete();

        return redirect()->back()->with('success', 'LGA deleted.');
    }

    public function update(State $state, LocalGovernment $lga, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sort_order' => ['required', 'integer'],
        ]);

        $lga->update($validated);

        return redirect()->back();
    }
}
