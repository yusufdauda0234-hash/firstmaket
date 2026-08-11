<?php

use App\Models\User;
use App\Modules\Catalog\Models\HeroSlide;
use App\Modules\Catalog\Services\HomeDataService;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The home page hero used to be three slides hardcoded in the frontend,
 * including a "60% OFF" claim with no campaign behind it. These slides are
 * admin content now — the tests that matter are that only real, active,
 * ordered slides reach the storefront, and that the screen is gated the
 * same way every other catalogue-shaping tool is (catalog.manage).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create(['user_type' => UserType::Staff]);
    $this->admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->admin->assignRole('Administrator');
});

function heroSlide(array $attributes = []): HeroSlide
{
    return HeroSlide::query()->create(array_merge([
        'eyebrow' => '🔥 Deals', 'title' => 'Test slide', 'description' => 'Test description.',
        'cta_label' => 'Go →', 'cta_target' => 'auth_gate', 'theme' => 'brand', 'emoji' => '🛍️',
        'offer_type' => 'static', 'offer_label' => 'Sellers pay', 'offer_value' => '₦0 fees',
        'is_active' => true, 'sort_order' => 0,
    ], $attributes));
}

it('creates a hero slide over HTTP', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/merchandising/hero-slides'), [
            'eyebrow' => '🔥 Super Deals', 'title' => 'Grab trusted deals', 'description' => 'Verified vendors.',
            'cta_label' => 'Grab It Now →', 'cta_target' => 'auth_gate', 'theme' => 'brand', 'emoji' => '🛍️',
            'offer_type' => 'from_price', 'offer_label' => 'Starting from', 'is_active' => true, 'sort_order' => 1,
        ])
        ->assertRedirect();

    expect(HeroSlide::query()->where('title', 'Grab trusted deals')->exists())->toBeTrue();
});

it('requires a fixed value when the offer type is static', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/merchandising/hero-slides'), [
            'eyebrow' => 'x', 'title' => 'x', 'description' => 'x', 'cta_label' => 'x',
            'cta_target' => 'vendor_register', 'theme' => 'brand', 'emoji' => '🛍️',
            'offer_type' => 'static', 'offer_label' => 'Sellers pay', 'offer_value' => '',
        ])
        ->assertSessionHasErrors('offer_value');
});

it('drops the stored offer value when a slide is switched away from static', function () {
    $slide = heroSlide(['offer_type' => 'static', 'offer_value' => '₦0 fees']);

    $this->actingAs($this->admin)
        ->put(adminUrl("/merchandising/hero-slides/{$slide->id}"), [
            'eyebrow' => $slide->eyebrow, 'title' => $slide->title, 'description' => $slide->description,
            'cta_label' => $slide->cta_label, 'cta_target' => $slide->cta_target, 'theme' => $slide->theme,
            'emoji' => $slide->emoji, 'offer_type' => 'from_price', 'offer_label' => 'Starting from',
        ])
        ->assertRedirect();

    expect($slide->refresh()->offer_value)->toBeNull();
});

it('only serves active slides to the home page, ordered', function () {
    heroSlide(['title' => 'Third', 'sort_order' => 3, 'is_active' => true]);
    heroSlide(['title' => 'First', 'sort_order' => 1, 'is_active' => true]);
    heroSlide(['title' => 'Hidden', 'sort_order' => 2, 'is_active' => false]);

    $titles = collect(app(HomeDataService::class)->heroSlides())->pluck('title');

    expect($titles->all())->toBe(['First', 'Third']);
});

it('applies the classic-three template only to an empty carousel', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/merchandising/hero-slides/template'), ['template' => 'classic'])
        ->assertRedirect();

    expect(HeroSlide::query()->count())->toBe(3);

    // A second application is refused rather than duplicating the set —
    // a carousel is ordered, so merging in more rows would not produce an
    // order anyone chose.
    $this->actingAs($this->admin)
        ->post(adminUrl('/merchandising/hero-slides/template'), ['template' => 'minimal'])
        ->assertRedirect();

    expect(HeroSlide::query()->count())->toBe(3);
});

it('keeps the hero slides screen behind catalog.manage', function () {
    $agent = User::factory()->create(['user_type' => UserType::Staff]);
    $agent->forceFill(['two_factor_confirmed_at' => now()])->save();
    $agent->assignRole('Support Agent');

    $this->actingAs($agent)
        ->get(adminUrl('/merchandising/hero-slides'))
        ->assertForbidden();
});
