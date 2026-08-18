<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReferralSettingsController extends Controller
{
    public function index(): Response
    {
        $rewardAmountKobo = (int) Setting::get('referrals.reward_amount_kobo', 50_000);

        return Inertia::render('Admin/Settings/Referrals', [
            'rewardAmountKobo' => $rewardAmountKobo,
            'rewardAmountNaira' => floor($rewardAmountKobo / 100),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reward_amount_naira' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $rewardAmountKobo = (int) ($validated['reward_amount_naira'] * 100);

        Setting::set('referrals.reward_amount_kobo', $rewardAmountKobo, 'referrals');

        return back()->with('success', 'Referral reward amount updated.');
    }
}
