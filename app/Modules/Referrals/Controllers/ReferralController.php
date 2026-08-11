<?php

namespace App\Modules\Referrals\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Referrals\Models\Referral;
use App\Modules\Referrals\Services\ReferralService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends Controller
{
    public function index(Request $request, ReferralService $referrals): Response
    {
        $referral = $referrals->codeFor($request->user());
        $sent = Referral::query()
            ->with(['referred:id,name', 'qualifiedPlan:id,uuid'])
            ->where('referrer_id', $request->user()->id)
            ->whereNotNull('referred_id')
            ->latest()
            ->get();

        return Inertia::render('Account/Referrals', [
            'code' => $referral->referral_code,
            'link' => route('referrals.capture', $referral->referral_code),
            'rewardAmountKobo' => $referral->reward_amount,
            'referrals' => $sent->map(fn (Referral $item) => [
                'name' => $item->referred?->name ?? 'Customer',
                'status' => $item->status,
                'rewardAmountKobo' => $item->reward_amount,
                'qualifiedAt' => $item->reward_credited_at?->toDateString(),
            ])->values(),
        ]);
    }

    public function capture(Request $request, string $code): RedirectResponse
    {
        $exists = Referral::query()->where('referral_code', $code)->exists();

        if ($exists) {
            $request->session()->put('referral_code', $code);
        }

        return redirect()->route('home', ['auth' => 'register']);
    }

    public function claimFromSession(Request $request, User $user, ReferralService $referrals): void
    {
        $code = $request->session()->pull('referral_code');

        if (is_string($code) && $code !== '') {
            $referrals->claim($code, $user);
        }
    }
}
