<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class SearchTerm extends Model
{
    protected $fillable = ['term', 'search_count', 'last_searched_at'];

    protected function casts(): array
    {
        return ['search_count' => 'integer', 'last_searched_at' => 'datetime'];
    }
}