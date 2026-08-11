<?php

namespace App\Modules\Referrals\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Referrals\Models\Referral;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function codeFor(User $user): Referral
    {
        return Referral::query()->firstOrCreate(
            ['referrer_id' => $user->id, 'referred_id' => null],
            [
                'referral_code' => $this->newCode(),
                'status' => 'pending',
                'reward_amount' => (int) Setting::get('referrals.reward_amount_kobo', 50_000),
            ],
        );
    }

    public function claim(string $code, User $referred): void
    {
        $referral = Referral::query()->where('referral_code', $code)->lockForUpdate()->first();

        if ($referral === null || $referral->referred_id !== null) {
            return;
        }

        if ($referral->referrer_id === $referred->id) {
            throw ValidationException::withMessages(['referral' => 'You cannot refer yourself.']);
        }

        $referral->forceFill(['referred_id' => $referred->id])->save();
    }

    private function newCode(): string
    {
        do {
            $code = Str::upper(Str::random(12));
        } while (Referral::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
