<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class ProductViewCount extends Model
{
    protected $fillable = ['product_id', 'viewed_on', 'view_count'];

    protected function casts(): array
    {
        return ['viewed_on' => 'date', 'view_count' => 'integer'];
    }
}