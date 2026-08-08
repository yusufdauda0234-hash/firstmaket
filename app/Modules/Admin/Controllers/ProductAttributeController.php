<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\StarterTemplates;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\AttributeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The vendor "add product" form builder.
 *
 * Staff decide what each kind of product must describe about itself — colour
 * and storage for phones, material and dimensions for furniture, a demo link
 * for anything that needs one — and the vendor form renders itself from those
 * definitions. New product types need no migration and no deploy.
 */
class ProductAttributeController extends Controller
{
    public function index(Request $request): Response
    {
        $categories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $attributes = ProductAttribute::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductAttribute $attribute) => [
                'id' => $attribute->id,
                'isBuiltIn' => $attribute->isBuiltIn(),
                'systemKey' => $attribute->system_key,
                'categoryId' => $attribute->category_id,
                'categoryLabel' => $attribute->category_id === null
                    ? 'Every category'
                    : $categories->firstWhere('id', $attribute->category_id)?->pathLabel() ?? 'Unknown',
                'key' => $attribute->key,
                'label' => $attribute->label,
                'type' => $attribute->type->value,
                'typeLabel' => $attribute->type->label(),
                'options' => $attribute->optionList(),
                'unit' => $attribute->unit,
                'helpText' => $attribute->help_text,
                'placeholder' => $attribute->placeholder,
                'isRequired' => $attribute->is_required,
                'isActive' => $attribute->is_active,
                'sortOrder' => $attribute->sort_order,
                'usageCount' => $attribute->values()->count(),
            ]);

        return Inertia::render('Admin/Catalog/ProductFields', [
            'attributes' => $attributes,
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'label' => $category->pathLabel(),
            ])->sortBy('label')->values(),
            'fieldTypes' => array_map(fn (AttributeType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'hasOptions' => $type->hasOptions(),
            ], AttributeType::cases()),
            'templates' => StarterTemplates::forDisplay(StarterTemplates::productFields()),
        ]);
    }

    /**
     * Switch several fields on or off at once.
     *
     * Off means vendors stop being asked for it while every answer already
     * given is kept — which is why this is offered in bulk and deletion is not.
     */
    public function bulkUpdate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:activate,deactivate'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer'],
        ], [
            'ids.required' => 'Select at least one field first.',
        ]);

        $active = $validated['action'] === 'activate';

        // Built-ins are excluded rather than rejected: a batch that happens to
        // include "Price" should still switch the custom fields, not fail
        // wholesale. Every product needs them, so they stay on regardless.
        $attributes = ProductAttribute::query()
            ->custom()
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($attributes as $attribute) {
            $attribute->update(['is_active' => $active]);
        }

        $builtInsSkipped = count($validated['ids']) - $attributes->count();

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.product_fields_bulk_'.$validated['action'],
            newValues: ['product_attribute_ids' => $attributes->pluck('id')->all()],
        );

        $count = $attributes->count();
        $message = $count.' field'.($count === 1 ? '' : 's').' '.($active ? 'switched on' : 'switched off').'.';

        if ($builtInsSkipped > 0) {
            $message .= " {$builtInsSkipped} built-in field".($builtInsSkipped === 1 ? '' : 's')
                .' left alone — every product needs those.';
        }

        return back()->with('success', $message);
    }

    /**
     * Lay down a ready-made set of listing fields.
     *
     * A template that names a category is scoped to it, so "Engine size" does
     * not turn up on a listing for shoes. If that category does not exist the
     * fields are created unscoped rather than dropped — a field on every
     * listing is a smaller wrong than a field nobody ever sees.
     */
    public function applyTemplate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $templates = StarterTemplates::productFields();

        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys($templates))],
        ]);

        $template = $templates[$validated['template']];

        $categoryId = $template['category'] === null
            ? null
            : Category::query()->where('name', $template['category'])->value('id');

        $added = 0;

        foreach ($template['rows'] as $row) {
            $key = Str::slug($row['label'], '_');

            $exists = ProductAttribute::query()
                ->where('key', $key)
                ->where('category_id', $categoryId)
                ->exists();

            if ($exists) {
                continue;
            }

            ProductAttribute::query()->create([
                'category_id' => $categoryId,
                'key' => $key,
                'label' => $row['label'],
                'type' => $row['type'],
                'options' => $row['options'] ?? null,
                'unit' => $row['unit'] ?? null,
                'is_required' => $row['is_required'] ?? false,
                'is_active' => true,
            ]);
            $added++;
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.product_fields_template_applied',
            newValues: ['template' => $validated['template'], 'added' => $added],
        );

        $where = $categoryId === null ? 'every listing' : $template['category'].' listings';

        return back()->with(
            $added > 0 ? 'success' : 'error',
            $added > 0
                ? $added.' field'.($added === 1 ? '' : 's')." added to {$where}."
                : 'Nothing added — those fields already exist.',
        );
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $this->validated($request);

        $attribute = ProductAttribute::query()->create($this->attributesFrom($validated));

        $auditLogger->log(
            actor: $request->user(),
            subject: $attribute,
            action: 'catalog.product_field_created',
            newValues: $attribute->only(['key', 'label', 'type', 'category_id']),
        );

        return back()->with('success', "“{$attribute->label}” added to the product form.");
    }

    public function update(Request $request, ProductAttribute $productAttribute, AuditLoggerContract $auditLogger): RedirectResponse
    {
        // A built-in field is backed by a real column on products, with its own
        // validation and business rules. Only its wording is configurable —
        // nothing here may make "Price" optional or turn it into a dropdown.
        if ($productAttribute->isBuiltIn()) {
            // Only the label. The hint, placeholder and sort-order boxes are
            // no longer on the form, so reading them from the request would
            // blank whatever a built-in already has every time somebody fixed
            // a typo in its label.
            $productAttribute->update([
                'label' => $request->string('label')->value() ?: $productAttribute->label,
            ]);

            $auditLogger->log(
                actor: $request->user(),
                subject: $productAttribute,
                action: 'admin.product_field_reworded',
                newValues: $productAttribute->only(['system_key', 'label', 'help_text', 'placeholder']),
            );

            return back()->with('success', "\"{$productAttribute->label}\" updated.");
        }

        $validated = $this->validated($request, $productAttribute);

        $before = $productAttribute->only(['key', 'label', 'type', 'category_id', 'is_required']);

        // Changing the type of a field vendors have already filled in would
        // leave stored answers that no longer validate — e.g. free text under
        // a field that is now a fixed list.
        if ($productAttribute->type->value !== $validated['type'] && $productAttribute->values()->exists()) {
            throw ValidationException::withMessages([
                'type' => 'This field already holds vendor answers, so its type cannot change. Deactivate it and add a replacement instead.',
            ]);
        }

        $productAttribute->update($this->attributesFrom($validated));

        $auditLogger->log(
            actor: $request->user(),
            subject: $productAttribute,
            action: 'catalog.product_field_updated',
            oldValues: $before,
            newValues: $productAttribute->only(['key', 'label', 'type', 'category_id', 'is_required']),
        );

        return back()->with('success', "“{$productAttribute->label}” updated.");
    }

    public function destroy(Request $request, ProductAttribute $productAttribute, AuditLoggerContract $auditLogger): RedirectResponse
    {
        if ($productAttribute->isBuiltIn()) {
            return back()->withErrors([
                'field' => 'Every product needs this field, so it cannot be deleted. You can reword it instead.',
            ]);
        }

        // Deleting would cascade the answers away with it. Vendors filled
        // those in; deactivating hides the field and keeps the history.
        if ($productAttribute->values()->exists()) {
            $count = $productAttribute->values()->count();

            throw ValidationException::withMessages([
                'field' => "“{$productAttribute->label}” is used by {$count} product(s). Switch it off instead of deleting it, so their details are not lost.",
            ]);
        }

        $label = $productAttribute->label;

        $auditLogger->log(
            actor: $request->user(),
            subject: $productAttribute,
            action: 'catalog.product_field_deleted',
            oldValues: $productAttribute->only(['key', 'label', 'type', 'category_id']),
        );

        $productAttribute->delete();

        return back()->with('success', "“{$label}” removed from the product form.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ProductAttribute $attribute = null): array
    {
        $type = $request->input('type');
        $needsOptions = in_array($type, [AttributeType::Select->value, AttributeType::Multiselect->value], true);

        return $request->validate([
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AttributeType::class)],
            'options' => [$needsOptions ? 'required' : 'nullable', 'array', 'max:60'],
            'options.*' => ['required', 'string', 'max:80'],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_required' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'options.required' => 'Add at least one choice for this kind of field.',
        ]) + ['__existing' => $attribute];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFrom(array $validated): array
    {
        /** @var ProductAttribute|null $existing */
        $existing = $validated['__existing'] ?? null;
        $type = AttributeType::from($validated['type']);

        return [
            'category_id' => $validated['category_id'] ?? null,
            // Derived from the label, and never changed afterwards: the key is
            // what stored answers are matched on, so renaming the label must
            // not orphan them.
            'key' => $existing?->key ?? $this->uniqueKey(
                $validated['label'],
                $validated['category_id'] ?? null,
            ),
            'label' => $validated['label'],
            'type' => $type->value,
            'options' => $type->hasOptions() ? array_values(array_unique($validated['options'] ?? [])) : null,
            // A unit only means anything on a number — "kg" under a dropdown
            // is noise — so it is dropped rather than carried on other types.
            'unit' => $type === AttributeType::Number ? ($validated['unit'] ?? null) : null,
            // help_text and placeholder are no longer on the form. Whatever a
            // field already has is left as it is rather than nulled, so
            // trimming the form does not quietly erase wording somebody wrote.
            'help_text' => $existing?->help_text,
            'placeholder' => $existing?->placeholder,
            'is_required' => $validated['is_required'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ];
    }

    private function uniqueKey(string $label, ?int $categoryId): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $key = $base;
        $suffix = 2;

        // Unique within the category. The DB unique index does not catch
        // duplicates among global fields (MySQL treats NULLs as distinct),
        // so the check has to happen here for both cases.
        while (ProductAttribute::query()
            ->where('key', $key)
            ->when($categoryId === null,
                fn ($query) => $query->whereNull('category_id'),
                fn ($query) => $query->where('category_id', $categoryId))
            ->exists()
        ) {
            $key = "{$base}_{$suffix}";
            $suffix++;
        }

        return $key;
    }
}
