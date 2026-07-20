<?php

namespace App\Modules\Catalog\Events;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an administrator approves a listing. Other modules react via
 * listeners (never by querying Catalog models directly) — e.g. Notifications
 * telling the vendor, or Referrals checking vendor-recruitment conversions.
 */
class ProductApproved
{
    use Dispatchable;

    public function __construct(public readonly Product $product) {}
}
