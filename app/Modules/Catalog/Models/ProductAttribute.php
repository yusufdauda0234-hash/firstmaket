<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Enums\AttributeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One admin-defined field on the vendor product form.
 *
 * A null category_id makes the field global; otherwise it belongs to that
 * category and is inherited by every category nested under it.
 *
 * @property int $id
 * @property string|null $system_key Set on the fields every product has.
 * @property int|null $category_id
 * @property string $key
 * @property string $label
 * @property AttributeType $type
 * @property array<int, string>|null $options
 * @property string|null $unit
 * @property string|null $help_text
 * @property string|null $placeholder
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 */
class ProductAttribute extends Model
{
    protected $fillable = [
        'system_key',
        'category_id',
        'key',
        'label',
        'type',
        'options',
        'unit',
        'help_text',
        'placeholder',
        'is_required',
        'is_active',
        'sort_order',
    ];

    /**
     * Is this one of the fields every product has?
     *
     * Built-ins are backed by a real column on products with validation and
     * business rules of their own, so only their wording is editable — the
     * field itself cannot be deleted, retyped or switched off.
     */
    public function isBuiltIn(): bool
    {
        return $this->system_key !== null;
    }

    /** @return Builder<ProductAttribute> */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->whereNull('system_key');
    }

    /** @return Builder<ProductAttribute> */
    public function scopeBuiltIn(Builder $query): Builder
    {
        return $query->whereNotNull('system_key');
    }

    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductAttributeValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /** @return array<int, string> */
    public function optionList(): array
    {
        return $this->type->hasOptions() ? ($this->options ?? []) : [];
    }

    /**
     * What the vendor form needs to render this field.
     *
     * @return array<string, mixed>
     */
    public function toFormField(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'options' => $this->optionList(),
            'unit' => $this->unit,
            'helpText' => $this->help_text,
            'placeholder' => $this->placeholder,
            'required' => $this->is_required,
        ];
    }
}
