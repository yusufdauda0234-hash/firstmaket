<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Services\HomeDataService;
use App\Modules\Catalog\Services\ProductAttributeService;
use App\Shared\Enums\AttributeType;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The catalogue tree and the admin-defined vendor form fields.
 *
 * Both exist so staff can shape what vendors list without a developer, which
 * makes the guard rails the important part: a tree that cannot be corrupted,
 * and fields whose definitions cannot silently destroy vendor answers.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = catalogStaff('Super Administrator');
});

/** Staff on the admin portal must be Staff-typed and past 2FA enrolment. */
function catalogStaff(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function catalogUrl(string $path): string
{
    return 'http://'.config('app.admin_domain').'/'.ltrim($path, '/');
}

// ── Categories ──────────────────────────────────────────────────────────

it('nests a category under a parent', function () {
    $parent = Category::factory()->create(['name' => 'Electronics']);

    $this->actingAs($this->staff)
        ->post(catalogUrl('catalog/categories'), ['name' => 'Phones', 'parent_id' => $parent->id])
        ->assertRedirect();

    $child = Category::query()->where('name', 'Phones')->firstOrFail();

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->pathLabel())->toBe('Electronics › Phones')
        ->and($child->depth())->toBe(1);
});

it('refuses to nest deeper than three levels', function () {
    $a = Category::factory()->create(['name' => 'A']);
    $b = Category::factory()->create(['name' => 'B', 'parent_id' => $a->id]);
    $c = Category::factory()->create(['name' => 'C', 'parent_id' => $b->id]);

    $this->actingAs($this->staff)
        ->post(catalogUrl('catalog/categories'), ['name' => 'D', 'parent_id' => $c->id])
        ->assertSessionHasErrors('parent_id');

    expect(Category::query()->where('name', 'D')->exists())->toBeFalse();
});

it('refuses to move a category inside its own sub-category', function () {
    // The trap: this would detach the whole branch into an unreachable cycle.
    $parent = Category::factory()->create(['name' => 'Electronics']);
    $child = Category::factory()->create(['name' => 'Phones', 'parent_id' => $parent->id]);

    $this->actingAs($this->staff)
        ->put(catalogUrl("catalog/categories/{$parent->id}"), [
            'name' => 'Electronics',
            'parent_id' => $child->id,
        ])
        ->assertSessionHasErrors('parent_id');

    expect($parent->refresh()->parent_id)->toBeNull();
});

it('refuses to delete a category that still holds products', function () {
    $category = Category::factory()->create();
    Product::factory()->approved()->create(['category_id' => $category->id]);

    $this->actingAs($this->staff)
        ->delete(catalogUrl("catalog/categories/{$category->id}"))
        ->assertSessionHasErrors('category');

    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue();
});

it('refuses to delete a category that still has sub-categories', function () {
    $parent = Category::factory()->create();
    Category::factory()->create(['parent_id' => $parent->id]);

    $this->actingAs($this->staff)
        ->delete(catalogUrl("catalog/categories/{$parent->id}"))
        ->assertSessionHasErrors('category');
});

it('allows the same name under different parents', function () {
    $phones = Category::factory()->create(['name' => 'Phones']);
    $laptops = Category::factory()->create(['name' => 'Laptops']);

    foreach ([$phones, $laptops] as $parent) {
        $this->actingAs($this->staff)
            ->post(catalogUrl('catalog/categories'), ['name' => 'Accessories', 'parent_id' => $parent->id])
            ->assertSessionHasNoErrors();
    }

    expect(Category::query()->where('name', 'Accessories')->count())->toBe(2);
});

// ── Product fields ──────────────────────────────────────────────────────

it('inherits a parent category field down the tree', function () {
    $electronics = Category::factory()->create(['name' => 'Electronics']);
    $phones = Category::factory()->create(['name' => 'Phones', 'parent_id' => $electronics->id]);

    ProductAttribute::query()->create([
        'category_id' => $electronics->id,
        'key' => 'warranty',
        'label' => 'Warranty',
        'type' => AttributeType::Text->value,
    ]);
    ProductAttribute::query()->create([
        'category_id' => null,
        'key' => 'brand',
        'label' => 'Brand',
        'type' => AttributeType::Text->value,
    ]);

    $fields = app(ProductAttributeService::class)->forCategory($phones);

    expect($fields->pluck('key')->all())->toEqualCanonicalizing(['warranty', 'brand']);
});

it('lets a child category override an inherited field', function () {
    $electronics = Category::factory()->create(['name' => 'Electronics']);
    $phones = Category::factory()->create(['name' => 'Phones', 'parent_id' => $electronics->id]);

    ProductAttribute::query()->create([
        'category_id' => $electronics->id,
        'key' => 'warranty',
        'label' => 'Warranty',
        'type' => AttributeType::Text->value,
    ]);
    ProductAttribute::query()->create([
        'category_id' => $phones->id,
        'key' => 'warranty',
        'label' => 'Warranty (months)',
        'type' => AttributeType::Number->value,
    ]);

    $fields = app(ProductAttributeService::class)->forCategory($phones);

    // The closest definition wins, and only one survives.
    expect($fields)->toHaveCount(1)
        ->and($fields->first()->label)->toBe('Warranty (months)');
});

it('builds validation rules from the definitions', function () {
    $category = Category::factory()->create();

    ProductAttribute::query()->create([
        'category_id' => $category->id,
        'key' => 'colour',
        'label' => 'Colour',
        'type' => AttributeType::Select->value,
        'options' => ['Red', 'Blue'],
        'is_required' => true,
    ]);

    $service = app(ProductAttributeService::class);
    $rules = $service->rules($service->forCategory($category));

    expect($rules)->toHaveKey('attributes.colour')
        ->and($rules['attributes.colour'][0])->toBe('required');
});

it('refuses to delete a field that vendors have already answered', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->approved()->create(['category_id' => $category->id]);

    $field = ProductAttribute::query()->create([
        'category_id' => $category->id,
        'key' => 'colour',
        'label' => 'Colour',
        'type' => AttributeType::Text->value,
    ]);

    app(ProductAttributeService::class)->sync($product, ['colour' => 'Red']);

    $this->actingAs($this->staff)
        ->delete(catalogUrl("catalog/product-fields/{$field->id}"))
        ->assertSessionHasErrors('field');

    expect(ProductAttribute::query()->whereKey($field->id)->exists())->toBeTrue();
});

it('refuses to retype a field that vendors have already answered', function () {
    // Free text already stored would not survive becoming a fixed list.
    $category = Category::factory()->create();
    $product = Product::factory()->approved()->create(['category_id' => $category->id]);

    $field = ProductAttribute::query()->create([
        'category_id' => $category->id,
        'key' => 'colour',
        'label' => 'Colour',
        'type' => AttributeType::Text->value,
    ]);

    app(ProductAttributeService::class)->sync($product, ['colour' => 'Burnt orange']);

    $this->actingAs($this->staff)
        ->put(catalogUrl("catalog/product-fields/{$field->id}"), [
            'label' => 'Colour',
            'type' => AttributeType::Select->value,
            'options' => ['Red', 'Blue'],
        ])
        ->assertSessionHasErrors('type');

    expect($field->refresh()->type)->toBe(AttributeType::Text);
});

it('keeps the key stable when the label is renamed', function () {
    $category = Category::factory()->create();

    $this->actingAs($this->staff)
        ->post(catalogUrl('catalog/product-fields'), [
            'category_id' => $category->id,
            'label' => 'Colour',
            'type' => AttributeType::Text->value,
        ]);

    $field = ProductAttribute::query()->where('label', 'Colour')->firstOrFail();
    expect($field->key)->toBe('colour');

    $this->actingAs($this->staff)
        ->put(catalogUrl("catalog/product-fields/{$field->id}"), [
            'label' => 'Colour / finish',
            'type' => AttributeType::Text->value,
        ]);

    // Renaming the label must not orphan stored answers.
    expect($field->refresh()->key)->toBe('colour')
        ->and($field->label)->toBe('Colour / finish');
});

it('drops answers to fields that no longer apply after a category change', function () {
    $phones = Category::factory()->create(['name' => 'Phones']);
    $shoes = Category::factory()->create(['name' => 'Shoes']);

    ProductAttribute::query()->create([
        'category_id' => $phones->id,
        'key' => 'storage',
        'label' => 'Storage',
        'type' => AttributeType::Text->value,
    ]);

    $product = Product::factory()->approved()->create(['category_id' => $phones->id]);
    $service = app(ProductAttributeService::class);
    $service->sync($product, ['storage' => '128GB']);

    expect($service->valuesFor($product))->toHaveKey('storage');

    $product->update(['category_id' => $shoes->id]);
    $service->sync($product->refresh(), []);

    expect($service->valuesFor($product))->toBe([]);
});

it('ignores answers for fields the form never offered', function () {
    // A hand-crafted POST must not be able to attach arbitrary attributes.
    $phones = Category::factory()->create(['name' => 'Phones']);
    $shoes = Category::factory()->create(['name' => 'Shoes']);

    $foreign = ProductAttribute::query()->create([
        'category_id' => $shoes->id,
        'key' => 'shoe_size',
        'label' => 'Shoe size',
        'type' => AttributeType::Text->value,
    ]);

    $product = Product::factory()->approved()->create(['category_id' => $phones->id]);

    app(ProductAttributeService::class)->sync($product, ['shoe_size' => '43']);

    expect($foreign->values()->count())->toBe(0);
});

it('shows saved answers as specifications on the product page', function () {
    $category = Category::factory()->create();

    ProductAttribute::query()->create([
        'category_id' => $category->id,
        'key' => 'weight',
        'label' => 'Weight',
        'type' => AttributeType::Number->value,
        'unit' => 'kg',
        'sort_order' => 1,
    ]);

    $product = Product::factory()->approved()->create(['category_id' => $category->id]);
    $service = app(ProductAttributeService::class);
    $service->sync($product, ['weight' => '2.5']);

    // listStyle and items are what let a bullet-list field draw a real <ul>;
    // an ordinary field carries them empty so the page falls back to `value`.
    expect($service->specificationsFor($product))->toBe([
        ['label' => 'Weight', 'value' => '2.5 kg', 'listStyle' => null, 'items' => []],
    ]);
});

it('keeps the field builder behind the catalog permission', function () {
    // Staff, and legitimately signed in — just without catalog.manage.
    $outsider = catalogStaff('Support Agent');

    $this->actingAs($outsider)
        ->get(catalogUrl('catalog/product-fields'))
        ->assertForbidden();
});

it('shows a parent category the products filed under its children', function () {
    $electronics = Category::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);
    $phones = Category::factory()->create(['name' => 'Phones', 'parent_id' => $electronics->id]);

    Product::factory()->approved()->create(['category_id' => $phones->id]);

    // Browsing the parent must not look empty just because its products were
    // organised into a sub-category.
    $this->get(route('catalog.index', ['category' => 'electronics']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('keeps sub-categories out of the top-level navigation', function () {
    $electronics = Category::factory()->create(['name' => 'Electronics']);
    Category::factory()->create(['name' => 'Phones', 'parent_id' => $electronics->id]);

    $navigation = app(HomeDataService::class)->categories();

    expect(collect($navigation)->pluck('name'))->toContain('Electronics')
        ->not->toContain('Phones');
});
