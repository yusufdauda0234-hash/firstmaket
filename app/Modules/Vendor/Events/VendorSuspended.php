<?php

namespace App\Modules\Vendor\Events;

use Illuminate\Foundation\Events\Dispatchable;

class VendorSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly int $vendorProfileId,
        public readonly int $userId,
        public readonly int $suspendedById,
        public readonly string $reason,
    ) {}
}
