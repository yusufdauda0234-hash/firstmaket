<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Shared\Enums\AttributeType;
use Illuminate\Support\Collection;

/**
 * The one place that answers "which fields does this category's product form
 * have, what are they validated against, and how do I save the answers".
 *
 * The vendor form, its validation, and the specifications table on the
 * product page all read from here, so a field defined once in admin cannot
 * fall out of step between them.
 */
class ProductAttributeService
{
    /**
     * Active fields for a category: its own, everything inherited from its
     * ancestors, and the global ones.
     *
     * A category may override an inherited field by reusing its key — the
     * most specific definition wins, which is what lets "Electronics" define
     * a generic "Warranty" that "Phones" can replace with its own wording.
     *
     * @return Collection<int, ProductAttribute>
     */
    public function forCategory(?Category $category): Collection
    {
        $ancestorIds = $category === null
            ? []
            : array_map(fn (Category $node) => $node->id, $category->ancestry());

        $attributes = ProductAttribute::query()
            // Built-ins describe fields the product form already renders by
            // hand — name, price, stock and the rest. Including them here asked
            // vendors for every one a second time as attributes.*, and the
            // resulting validation errors had no field to attach to, so a
            // submission just silently failed.
            ->custom()
            ->where('is_active', true)
            ->where(function ($query) use ($ancestorIds) {
                $query->whereNull('category_id');

                if ($ancestorIds !== []) {
                    $query->orWhereIn('category_id', $ancestorIds);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Closest ancestor wins. ancestry() runs self → root, so a lower
        // index means more specific.
        $distance = array_flip($ancestorIds);

        return $attributes
            ->groupBy('key')
            ->map(function (Collection $sameKey) use ($distance) {
                return $sameKey->sortBy(fn (ProductAttribute $attribute) => $attribute->category_id === null
                    ? PHP_INT_MAX
                    : ($distance[$attribute->category_id] ?? PHP_INT_MAX - 1))->first();
            })
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Validation rules for the vendor form, keyed "attributes.<key>".
     *
     * @param  Collection<int, ProductAttribute>  $attributes
     * @return array<string, mixed>
     */
    public function rules(Collection $attributes): array
    {
        $rules = [];

        foreach ($attributes as $attribute) {
            $field = "attributes.{$attribute->key}";
            $rules[$field] = $attribute->type->rulesFor($attribute->is_required, $attribute->optionList());

            $each = $attribute->type->eachRulesFor($attribute->optionList());

            if ($each !== null) {
                $rules["{$field}.*"] = $each;
            }
        }

        return $rules;
    }

    /**
     * Friendly names so errors read "Colour is required", not
     * "attributes.colour is required".
     *
     * @param  Collection<int, ProductAttribute>  $attributes
     * @return array<string, string>
     */
    public function attributeNames(Collection $attributes): array
    {
        return $attributes
            ->mapWithKeys(fn (ProductAttribute $attribute) => [
                "attributes.{$attribute->key}" => strtolower($attribute->label),
            ])
            ->all();
    }

    /**
     * Replace a product's answers with the submitted ones.
     *
     * Only fields that belong to the product's category are written, so a
     * hand-crafted POST cannot smuggle in values for a field the form never
     * offered. Blank answers delete the row rather than storing an empty
     * string, keeping the specifications table free of empty rows.
     *
     * @param  array<string, mixed>  $submitted  Keyed by attribute key.
     */
    public function sync(Product $product, array $submitted): void
    {
        $attributes = $this->forCategory($product->category);

        foreach ($attributes as $attribute) {
            $raw = $submitted[$attribute->key] ?? null;
            $value = $attribute->type->cast($raw);

            $isBlank = $value === null
                || $value === ''
                || ($attribute->type->hasOptions() && $value === [])
                || (is_array($value) && $value === []);

            if ($isBlank && ! $attribute->is_required) {
                ProductAttributeValue::query()
                    ->where('product_id', $product->id)
                    ->where('product_attribute_id', $attribute->id)
                    ->delete();

                continue;
            }

            ProductAttributeValue::query()->updateOrCreate(
                ['product_id' => $product->id, 'product_attribute_id' => $attribute->id],
                ['value' => $value],
            );
        }

        // A field retired or moved to another category leaves values behind;
        // drop anything no longer on this product's form.
        ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->whereNotIn('product_attribute_id', $attributes->pluck('id'))
            ->delete();
    }

    /**
     * Saved answers keyed by attribute key, for repopulating the edit form.
     *
     * @return array<string, mixed>
     */
    public function valuesFor(Product $product): array
    {
        return ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->with('attribute')
            ->get()
            ->filter(fn (ProductAttributeValue $row) => $row->attribute !== null)
            ->mapWithKeys(fn (ProductAttributeValue $row) => [$row->attribute->key => $row->value])
            ->all();
    }

    /**
     * Specification rows for the product page, in form order.
     *
     * A list field also carries its items, so the page can draw a real <ul>
     * instead of running every point together into one paragraph. `value` is
     * still filled in for both, so anything that only knows how to print a
     * string keeps working.
     *
     * @return array<int, array{label: string, value: string, listStyle: string|null, items: array<int, string>}>
     */
    public function specificationsFor(Product $product): array
    {
        $values = ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('product_attribute_id');

        $rows = [];

        foreach ($this->forCategory($product->category) as $attribute) {
            $row = $values->get($attribute->id);

            if ($row === null || $row->value === null || $row->value === '' || $row->value === []) {
                continue;
            }

            $display = $attribute->type->display($row->value);
            $items = $attribute->type->items($row->value);

            $rows[] = [
                'label' => $attribute->label,
                'value' => $attribute->unit ? "{$display} {$attribute->unit}" : $display,
                'listStyle' => match ($attribute->type) {
                    AttributeType::BulletList => 'bullet',
                    AttributeType::NumberedList => 'numbered',
                    default => null,
                },
                'items' => $items,
            ];
        }

        return $rows;
    }

    /**
     * Specification rows aligned across several products, for the comparison
     * table.
     *
     * Unlike {@see specificationsFor()}, which walks one product's own fields,
     * this walks the union of the compared products' fields so a row exists
     * wherever *any* of them has a value — an attribute one phone declares and
     * another leaves blank is exactly the difference worth seeing, and hiding
     * the row would silently drop it.
     *
     * Products from different categories therefore compare cleanly: shared
     * fields line up, and anything only one side defines shows as "—" for the
     * rest.
     *
     * Deliberately no winner is declared on these rows. A winner needs a
     * direction, and the direction is not derivable from the type: more RAM is
     * better, less weight is better, and "Colour: red" has no better at all.
     * Guessing would be worse than not marking one — so the table shows the
     * differences and dims the rows where every product agrees. Marking these
     * properly needs a `winner_rule` on the attribute definition (highest /
     * lowest / none), set by staff who know what the field means.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array{key: string, label: string, values: array<string, string|null>, same: bool}>
     */
    public function comparisonRows(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $valuesByProduct = ProductAttributeValue::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => $rows->keyBy('product_attribute_id'));

        // Union of every compared product's fields, keeping each category's
        // own ordering and never listing the same key twice.
        $attributes = collect();

        foreach ($products as $product) {
            foreach ($this->forCategory($product->category) as $attribute) {
                if (! $attributes->has($attribute->key)) {
                    $attributes->put($attribute->key, $attribute);
                }
            }
        }

        $rows = [];

        foreach ($attributes as $attribute) {
            $values = [];
            $anyPresent = false;

            foreach ($products as $product) {
                $row = $valuesByProduct->get($product->id)?->get($attribute->id);
                $raw = $row?->value;

                if ($raw === null || $raw === '' || $raw === []) {
                    $values[$product->uuid] = null;

                    continue;
                }

                $anyPresent = true;
                $display = $attribute->type->display($raw);
                $values[$product->uuid] = $attribute->unit ? "{$display} {$attribute->unit}" : $display;
            }

            // No product filled this one in — nothing to compare.
            if (! $anyPresent) {
                continue;
            }

            $rows[] = [
                'key' => $attribute->key,
                'label' => $attribute->label,
                'values' => $values,
                // Every product gave the same answer, so the row can recede
                // and let the real differences carry the eye.
                'same' => count(array_unique(array_map(
                    fn ($value) => $value === null ? '' : $value,
                    $values,
                ), SORT_REGULAR)) === 1,
            ];
        }

        return $rows;
    }
}
