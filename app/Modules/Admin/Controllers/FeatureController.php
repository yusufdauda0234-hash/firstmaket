<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeatureController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings/Features', [
            'features' => collect(Features::all())->map(fn (string $feature) => [
                'key' => $feature,
                'enabled' => Features::enabled($feature),
            ])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['feature' => ['required', 'string'], 'enabled' => ['required', 'boolean']]);
        Features::set($data['feature'], (bool) $data['enabled']);

        return back()->with('success', 'Feature flag updated.');
    }
}