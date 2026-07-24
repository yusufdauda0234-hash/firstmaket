<?php

namespace App\Modules\Catalog\Listeners;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductStatusService;
use App\Modules\Vendor\Events\VendorSuspended;
use App\Shared\Enums\ProductStatus;

/**
 * Catalog's reaction to a vendor suspension: every approved listing is
 * delisted so nothing from the suspended vendor stays visible to customers
 * (docs/FirstMaket_Implementation_Plan.md Sprint 3 QA). Reinstatement does
 * not relist automatically — the vendor resubmits for review.
 */
class DelistSuspendedVendorProducts
{
    public function __construct(private readonly ProductStatusService $statusService) {}

    public function handle(VendorSuspended $event): void
    {
        $actor = User::query()->find($event->suspendedById);

        Product::query()
            ->where('vendor_id', $event->vendorProfileId)
            ->where('status', ProductStatus::Approved)
            ->get()
            ->each(fn (Product $product) => $this->statusService->delist(
                $product,
                $actor,
                'Vendor suspended: '.$event->reason,
            ));
    }
}
