<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vendor-supplied answer to one admin-defined field.
 *
 * @property int $id
 * @property int $product_id
 * @property int $product_attribute_id
 * @property mixed $value
 */
class ProductAttributeValue extends Model
{
    protected $fillable = [
        'product_id',
        'product_attribute_id',
        'value',
    ];

    protected function casts(): array
    {
        // JSON, so one column holds strings, numbers, booleans and the arrays
        // a multiselect produces.
        return ['value' => 'array'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductAttribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }
}
