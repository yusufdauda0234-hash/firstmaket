<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * FAQ entry (docs/firstmarket-Database_Schema.md section 10). Seeded via
 * FaqSeeder; published rows render on the public FAQ page and the Support
 * Center.
 *
 * @property int $id
 * @property string $category
 * @property string $question
 * @property string $answer
 * @property string $status
 * @property int $sort_order
 */
class Faq extends Model
{
    protected $fillable = [
        'category',
        'question',
        'answer',
        'status',
        'sort_order',
    ];

    /** @param  Builder<Faq>  $query
     * @return Builder<Faq> */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->orderBy('category')->orderBy('sort_order');
    }
}
