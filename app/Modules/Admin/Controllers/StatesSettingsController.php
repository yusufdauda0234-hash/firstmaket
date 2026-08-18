<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Models\Country;
use App\Modules\Settings\Models\State;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatesSettingsController extends Controller
{
    public function index(Country $country): Response
    {
        return Inertia::render('Admin/Settings/States', [
            'country' => [
                'id' => $country->id,
                'name' => $country->name,
                'code' => $country->code,
            ],
            'states' => $country->states()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (State $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'code' => $s->code,
                    'isActive' => $s->is_active,
                    'sortOrder' => $s->sort_order,
                ])
                ->all(),
        ]);
    }

    public function store(Request $request, Country $country): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);

        State::create([
            'country_id' => $country->id,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'is_active' => true,
            'sort_order' => State::where('country_id', $country->id)->max('sort_order') + 1,
        ]);

        return redirect()->back()->with('success', 'State added successfully.');
    }

    public function toggle(Country $country, State $state): RedirectResponse
    {
        $state->update(['is_active' => !$state->is_active]);

        return redirect()->back()->with('success', 'State updated.');
    }

    public function destroy(Country $country, State $state): RedirectResponse
    {
        $state->delete();

        return redirect()->back()->with('success', 'State deleted.');
    }

    public function update(Country $country, State $state, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sort_order' => ['required', 'integer'],
        ]);

        $state->update($validated);

        return redirect()->back();
    }
}
