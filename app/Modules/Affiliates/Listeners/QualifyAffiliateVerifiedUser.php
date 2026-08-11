<?php

namespace App\Modules\Affiliates\Listeners;

use App\Models\User;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Auth\Events\UserIdentifierVerified;

class QualifyAffiliateVerifiedUser
{
    public function __construct(private readonly AffiliateService $affiliates) {}

    public function handle(UserIdentifierVerified $event): void
    {
        $user = User::query()->find($event->userId);

        if ($user !== null) {
            $this->affiliates->qualifyVerifiedUser($user);
        }
    }
}
