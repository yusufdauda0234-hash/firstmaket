<?php

namespace App\Modules\Affiliates\Listeners;

use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Catalog\Events\ProductApproved;

class QualifyAffiliateVendor
{
    public function __construct(private readonly AffiliateService $affiliateService) {}

    public function handle(ProductApproved $event): void
    {
        $this->affiliateService->qualifyApprovedVendorProduct($event->product);
    }
}