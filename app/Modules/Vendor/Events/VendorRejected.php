<?php

namespace App\Modules\Vendor\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

class VendorRejected implements DomainEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $vendorProfileId,
        public readonly int $vendorUserId,
        public readonly int $rejectedById,
        public readonly string $reason,
    ) {}
}
