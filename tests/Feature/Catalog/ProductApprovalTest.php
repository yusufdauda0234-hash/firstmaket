<?php

use App\Models\User;
use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Shared\Enums\ProductStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;

function productAdminUrl(string $path): string
{
    return 'http://'.config('app.admin_domain').'/'.ltrim($path, '/');
}

/**
 * Sprint 3: admin product approval queue (admin subdomain, permission-gated).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->staff()->create();
    $this->admin->assignRole('Administrator');
    $this->admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->category = Category::factory()->create();
});

it('lists pending products for an administrator', function () {
    Product::factory()->pending()->create(['category_id' => $this->category->id, 'name' => 'Pending Freezer']);

    $this->actingAs($this->admin)
        ->get(productAdminUrl('products'))
        ->assertOk();
});

it('approves a pending product and fires the domain event', function () {
    Event::fake([ProductApproved::class]);

    $product = Product::factory()->pending()->create(['category_id' => $this->category->id]);

    $this->actingAs($this->admin)
        ->post(productAdminUrl("products/{$product->uuid}/approve"))
        ->assertRedirect();

    $product->refresh();

    expect($product->status)->toBe(ProductStatus::Approved)
        ->and($product->approved_by)->toBe($this->admin->id)
        ->and($product->approved_at)->not->toBeNull();

    Event::assertDispatched(ProductApproved::class);

    $this->assertDatabaseHas('audit_logs', [
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'action' => 'catalog.product_status_changed',
    ]);
});

it('rejects a pending product with a reason the vendor can see', function () {
    $product = Product::factory()->pending()->create(['category_id' => $this->category->id]);

    $this->actingAs($this->admin)
        ->post(productAdminUrl("products/{$product->uuid}/reject"), ['reason' => 'Images are too blurry.'])
        ->assertRedirect();

    $product->refresh();

    expect($product->status)->toBe(ProductStatus::Rejected)
        ->and($product->rejection_reason)->toBe('Images are too blurry.');
});

it('refuses to approve a product that is not pending', function () {
    $product = Product::factory()->approved()->create(['category_id' => $this->category->id]);

    $this->actingAs($this->admin)
        ->post(productAdminUrl("products/{$product->uuid}/approve"))
        ->assertSessionHasErrors('status');
});

it('blocks staff without the products.approve permission', function () {
    $support = User::factory()->staff()->create(['two_factor_confirmed_at' => now()]);
    $support->assignRole('Support Agent');

    $product = Product::factory()->pending()->create(['category_id' => $this->category->id]);

    $this->actingAs($support)
        ->post(productAdminUrl("products/{$product->uuid}/approve"))
        ->assertForbidden();
});
