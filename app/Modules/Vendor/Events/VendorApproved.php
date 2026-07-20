<?php

namespace App\Modules\Vendor\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when an Administrator approves a vendor. Other modules (Catalog
 * unlocking listings, Notifications emailing the vendor) subscribe to this
 * instead of reaching into the Vendor module.
 */
class VendorApproved implements DomainEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $vendorProfileId,
        public readonly int $vendorUserId,
        public readonly int $approvedById,
    ) {}
}
