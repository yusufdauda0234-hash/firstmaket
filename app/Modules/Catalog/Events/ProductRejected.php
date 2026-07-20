<?php

namespace App\Modules\Catalog\Events;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;

class ProductRejected
{
    use Dispatchable;

    public function __construct(public readonly Product $product) {}
}
