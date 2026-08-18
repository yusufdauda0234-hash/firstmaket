<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CountriesSettingsController extends Controller
{
    public function index(): Response
    {
        $countries = Country::orderBy('sort_order')->get();

        return Inertia::render('Admin/Settings/Countries', [
            'countries' => $countries->map(fn (Country $country) => [
                'id' => $country->id,
                'code' => $country->code,
                'name' => $country->name,
                'isActive' => $country->is_active,
                'sortOrder' => $country->sort_order,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2', 'unique:countries,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        Country::create($validated);

        return back()->with('success', "Country \"{$validated['name']}\" added.");
    }

    public function update(Request $request, Country $country): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
        ]);

        $country->update($validated);

        return back()->with('success', "Country updated.");
    }

    public function destroy(Country $country): RedirectResponse
    {
        $country->delete();

        return back()->with('success', "Country \"{$country->name}\" removed.");
    }

    public function toggleActive(Request $request, Country $country): RedirectResponse
    {
        $country->update(['is_active' => !$country->is_active]);

        $status = $country->is_active ? 'active' : 'inactive';

        return back()->with('success', "Country set to {$status}.");
    }
}
