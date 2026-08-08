<?php

use App\Models\User;
use App\Modules\Admin\Support\StarterTemplates;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\DisplayCurrency;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Orders\Models\DeliveryRate;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Savings\Models\PlanTerm;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * One-click starter settings.
 *
 * These are hand-written arrays of column names, so the only thing that
 * proves one is not a typo away from a query exception is creating the rows.
 * Every template is applied here — a template nobody exercises is a landmine
 * with a friendly label on it.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->admin = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Super Administrator');
});

function templateUrl(string $path): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).$path;
}

// ── Every template produces valid rows ──────────────────────────────────

dataset('templates', [
    'plan terms' => ['/settings/plan-terms/template', PlanTerm::class, 'planTerms'],
    'currencies' => ['/settings/currencies/template', DisplayCurrency::class, 'currencies'],
    'product fields' => ['/catalog/product-fields/template', ProductAttribute::class, 'productFields'],
    'delivery rates' => ['/settings/delivery-rates/template', DeliveryRate::class, 'deliveryRates'],
    'promo codes' => ['/settings/promo-codes/template', PromoCode::class, 'promoCodes'],
]);

it('creates every row in every template', function (string $path, string $model, string $set) {
    $templates = StarterTemplates::{$set}();

    foreach ($templates as $key => $template) {
        // Each template starts from a clean table, so a partial result is a
        // real failure rather than a collision with the one before it.
        $model::query()->delete();

        $this->actingAs($this->admin)
            ->post(templateUrl($path), ['template' => $key])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        expect($model::query()->count())
            ->toBe(count($template['rows']), "template '{$key}' did not create all its rows");
    }
})->with('templates');

it('refuses a template that does not exist', function (string $path) {
    $this->actingAs($this->admin)
        ->post(templateUrl($path), ['template' => 'not-a-template'])
        ->assertSessionHasErrors('template');
})->with('templates');

it('is closed to staff without the permission', function (string $path, string $model) {
    $agent = User::factory()->create(['user_type' => UserType::Staff, 'two_factor_confirmed_at' => now()]);
    $agent->assignRole('Support Agent');

    $before = $model::query()->count();

    $this->actingAs($agent)->post(templateUrl($path), ['template' => 'x'])->assertForbidden();

    expect($model::query()->count())->toBe($before);
})->with('templates');

// ── Applying twice does not duplicate ───────────────────────────────────

it('leaves rows alone on a second click', function (string $path, string $model, string $set) {
    $key = array_key_first(StarterTemplates::{$set}());
    $model::query()->delete();

    $this->actingAs($this->admin)->post(templateUrl($path), ['template' => $key]);
    $after = $model::query()->count();

    // A template is a starting point. Clicking it again must not undo an
    // edit somebody made by hand, and must not create a second copy.
    $this->actingAs($this->admin)->post(templateUrl($path), ['template' => $key]);

    expect($model::query()->count())->toBe($after);
})->with('templates');

// ── The parts that carry a decision ─────────────────────────────────────

it('derives the instalment count from the cadence, never trusts the template', function () {
    PlanTerm::query()->delete();

    $this->actingAs($this->admin)->post(templateUrl('/settings/plan-terms/template'), ['template' => 'full']);

    foreach (PlanTerm::query()->get() as $term) {
        expect($term->installments)->toBe($term->cadence->installmentsFor($term->duration_months))
            ->and($term->installments)->toBeGreaterThan(0);
    }
});

it('always includes a default delivery rate, whatever the template', function () {
    // Without the default row every unpriced state ships free, so a template
    // that omitted it would leave the shop worse off than before.
    foreach (array_keys(StarterTemplates::deliveryRates()) as $key) {
        DeliveryRate::query()->delete();

        $this->actingAs($this->admin)
            ->post(templateUrl('/settings/delivery-rates/template'), ['template' => $key]);

        expect(DeliveryRate::query()->whereNull('state')->exists())
            ->toBeTrue("template '{$key}' left no default rate");
    }
});

it('never lets a percentage promo template run without a ceiling', function () {
    foreach (array_keys(StarterTemplates::promoCodes()) as $key) {
        PromoCode::query()->delete();

        $this->actingAs($this->admin)
            ->post(templateUrl('/settings/promo-codes/template'), ['template' => $key]);

        foreach (PromoCode::query()->where('type', 'percent')->get() as $code) {
            expect($code->max_discount_kobo)->not->toBeNull("'{$code->code}' has no cap");
        }
    }
});

it('creates promo codes switched off', function () {
    PromoCode::query()->delete();

    $this->actingAs($this->admin)
        ->post(templateUrl('/settings/promo-codes/template'), ['template' => 'welcome']);

    // A discount going live because somebody was browsing templates is the
    // one mistake this screen must not make.
    expect(PromoCode::query()->firstOrFail()->is_active)->toBeFalse();
});

it('scopes a category template to that category when it exists', function () {
    ProductAttribute::query()->delete();
    $electronics = Category::factory()->create(['name' => 'Electronics']);

    $this->actingAs($this->admin)
        ->post(templateUrl('/catalog/product-fields/template'), ['template' => 'electronics']);

    foreach (ProductAttribute::query()->get() as $field) {
        expect($field->category_id)->toBe($electronics->id);
    }
});

it('still creates the fields when the category is missing', function () {
    ProductAttribute::query()->delete();
    Category::query()->where('name', 'Electronics')->delete();

    $this->actingAs($this->admin)
        ->post(templateUrl('/catalog/product-fields/template'), ['template' => 'electronics']);

    // A field on every listing is a smaller wrong than a template that
    // silently does nothing.
    expect(ProductAttribute::query()->count())->toBeGreaterThan(0)
        ->and(ProductAttribute::query()->whereNotNull('category_id')->count())->toBe(0);
});

it('gives every select field something to choose from', function () {
    ProductAttribute::query()->delete();

    foreach (array_keys(StarterTemplates::productFields()) as $key) {
        $this->actingAs($this->admin)
            ->post(templateUrl('/catalog/product-fields/template'), ['template' => $key]);
    }

    $choosers = ProductAttribute::query()->whereIn('type', ['select', 'multiselect'])->get();

    expect($choosers)->not->toBeEmpty();

    foreach ($choosers as $field) {
        expect($field->options)->not->toBeEmpty("'{$field->label}' has no options");
    }
});

it('offers naira in every currency template', function () {
    // Everything converts from it and Paystack settles nothing else, so a
    // template without it would break pricing rather than extend it.
    foreach (array_keys(StarterTemplates::currencies()) as $key) {
        DisplayCurrency::query()->delete();

        $this->actingAs($this->admin)
            ->post(templateUrl('/settings/currencies/template'), ['template' => $key]);

        expect(DisplayCurrency::query()->where('code', 'NGN')->exists())
            ->toBeTrue("template '{$key}' has no naira");
    }
});

// ── The pages offer them ────────────────────────────────────────────────

it('lists the templates on each settings screen', function () {
    foreach ([
        '/settings/plan-terms',
        '/settings/currencies',
        '/catalog/product-fields',
        '/settings/delivery-rates',
        '/settings/promo-codes',
    ] as $path) {
        $this->actingAs($this->admin)
            ->get(templateUrl($path))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('templates'));
    }
});
