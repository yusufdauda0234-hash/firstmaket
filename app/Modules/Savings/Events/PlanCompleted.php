<?php

namespace App\Modules\Savings\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

class PlanCompleted implements DomainEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $planId,
        public readonly int $userId,
        public readonly int $completedSavingsKobo,
    ) {}
}
