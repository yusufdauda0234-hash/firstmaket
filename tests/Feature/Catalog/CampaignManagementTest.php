<?php

use App\Models\User;
use App\Modules\Catalog\Models\Campaign;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SearchTerm;
use App\Modules\Catalog\Services\CampaignService;
use App\Modules\Catalog\Services\HomeDataService;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function liveCampaign(array $attributes = []): Campaign
{
    return Campaign::query()->create(array_merge([
        'name' => 'Weekend Flash Sale',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
    ], $attributes));
}

it('resolves the cheapest live campaign price and falls back after expiry', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 100_000]);
    $campaign = liveCampaign();
    $campaign->products()->attach($product, [
        'sale_price_kobo' => 80_000,
        'stock_cap' => 3,
        'sold_quantity' => 1,
    ]);

    $service = app(CampaignService::class);
    expect($service->priceFor($product, 2)['unitPriceKobo'])->toBe(80_000)
        ->and($service->priceFor($product, 3)['unitPriceKobo'])->toBe(100_000);

    $campaign->update(['ends_at' => now()->subMinute()]);

    expect($service->priceFor($product)['unitPriceKobo'])->toBe(100_000);
});

it('reserves campaign stock atomically and rejects quantities over the cap', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 100_000]);
    $campaign = liveCampaign();
    $campaign->products()->attach($product, [
        'sale_price_kobo' => 80_000,
        'stock_cap' => 3,
        'sold_quantity' => 2,
    ]);
    $campaignProductId = (int) DB::table('campaign_products')
        ->where('campaign_id', $campaign->id)
        ->where('product_id', $product->id)
        ->value('id');

    // Returns false rather than throwing: the only caller runs inside the
    // Paystack webhook's transaction, where an uncaught exception would roll
    // back the webhook_verified_at write and strand the customer's payment.
    expect(app(CampaignService::class)->reserve($campaignProductId, 2))->toBeFalse();

    expect((int) $campaign->products()->first()->pivot->sold_quantity)->toBe(2);
});

it('preserves sold quantities when a campaign is edited', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 100_000]);
    $campaign = liveCampaign();
    $campaign->products()->attach($product, [
        'sale_price_kobo' => 80_000,
        'stock_cap' => 5,
        'sold_quantity' => 2,
    ]);

    app(CampaignService::class)->update($campaign, [
        'name' => 'Updated Flash Sale',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
        'is_active' => true,
        'products' => [[
            'product_id' => $product->id,
            'sale_price_kobo' => 75_000,
            'stock_cap' => 5,
        ]],
    ]);

    expect((int) $campaign->products()->first()->pivot->sold_quantity)->toBe(2)
        ->and((int) $campaign->products()->first()->pivot->sale_price_kobo)->toBe(75_000);
});

it('records normalized catalog search terms', function () {
    Product::factory()->approved()->create(['name' => 'Samsung Freezer']);

    $this->get('/catalog?query=FREEZER')->assertOk();
    $this->get('/catalog?query=freezer')->assertOk();

    expect(SearchTerm::query()->where('term', 'freezer')->value('search_count'))->toBe(2);
});

it('counts browsing into a category as search interest too, not just typed queries', function () {
    $category = \App\Modules\Catalog\Models\Category::factory()->create(['name' => 'Electronics']);

    $this->get('/catalog?category='.$category->slug)->assertOk();
    $this->get('/catalog?category='.$category->slug.'&query=television')->assertOk();

    expect(SearchTerm::query()->where('term', 'electronics')->value('search_count'))->toBe(2)
        ->and(SearchTerm::query()->where('term', 'television')->value('search_count'))->toBe(1);
});

it('counts real orders placed in the last hour, and nothing older', function () {
    $product = Product::factory()->approved()->create();

    \App\Modules\Orders\Models\Order::factory()->create(['product_id' => $product->id, 'vendor_id' => $product->vendor_id, 'created_at' => now()->subMinutes(10)]);
    \App\Modules\Orders\Models\Order::factory()->create(['product_id' => $product->id, 'vendor_id' => $product->vendor_id, 'created_at' => now()->subMinutes(5)]);
    \App\Modules\Orders\Models\Order::factory()->create(['product_id' => $product->id, 'vendor_id' => $product->vendor_id, 'created_at' => now()->subHours(2)]);

    expect(app(HomeDataService::class)->recentOrderCount())->toBe(2);
});

it('shows the real campaign deal price and end time on the home page section', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 100_000]);
    $campaign = liveCampaign(['ends_at' => now()->addHours(3)]);
    $campaign->products()->attach($product, [
        'sale_price_kobo' => 75_000,
        'stock_cap' => null,
        'sold_quantity' => 0,
    ]);

    $home = collect(app(HomeDataService::class)->campaignProducts())->firstWhere('uuid', $product->uuid);

    expect($home)->not->toBeNull()
        ->and($home['priceKobo'])->toBe(75_000)
        ->and($home['compareAtPriceKobo'])->toBe(100_000)
        ->and($home['campaignEndsAt'])->not->toBeNull();
});

it('drops a product from the home deals section once its only campaign has expired', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 100_000]);
    $campaign = liveCampaign(['ends_at' => now()->subMinute()]);
    $campaign->products()->attach($product, ['sale_price_kobo' => 75_000, 'sold_quantity' => 0]);

    expect(collect(app(HomeDataService::class)->campaignProducts())->firstWhere('uuid', $product->uuid))
        ->toBeNull();

    // Ordinary sections never apply campaign pricing regardless — that stays
    // scoped to the dedicated deals section.
    $home = collect(app(HomeDataService::class)->newestProducts())->firstWhere('uuid', $product->uuid);
    expect($home['priceKobo'])->toBe(100_000)
        ->and($home['campaignEndsAt'])->toBeNull();
});

it('builds a starter campaign from real approved products, discounted below their sticker price', function () {
    $actor = User::factory()->create();
    $products = Product::factory()->count(3)->approved()->create(['price_kobo' => 100_000]);

    $campaign = app(CampaignService::class)->quickStart($actor);

    expect($campaign->is_active)->toBeTrue()
        ->and($campaign->products)->toHaveCount(3);

    foreach ($campaign->products as $product) {
        expect((int) $product->pivot->sale_price_kobo)
            ->toBeLessThan(100_000)
            ->toBe(90_000);
    }
});

it('refuses a starter campaign when there are no eligible approved products', function () {
    $actor = User::factory()->create();

    expect(fn () => app(CampaignService::class)->quickStart($actor))->toThrow(ValidationException::class);
});

it('does not pick a product that is already in a live campaign for the starter set', function () {
    $actor = User::factory()->create();
    $alreadyRunning = Product::factory()->approved()->create(['price_kobo' => 100_000]);
    $eligible = Product::factory()->approved()->create(['price_kobo' => 50_000]);

    liveCampaign()->products()->attach($alreadyRunning, ['sale_price_kobo' => 90_000, 'sold_quantity' => 0]);

    $campaign = app(CampaignService::class)->quickStart($actor);

    expect($campaign->products->pluck('id')->all())->toBe([$eligible->id]);
});

it('creates a starter campaign over HTTP behind catalog.manage', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Product::factory()->count(2)->approved()->create();

    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $admin->assignRole('Administrator');

    $this->actingAs($admin)
        ->post(adminUrl('/merchandising/campaigns/quick-start'))
        ->assertRedirect();

    expect(Campaign::query()->where('name', 'Quick Flash Sale')->exists())->toBeTrue();

    $agent = User::factory()->create(['user_type' => UserType::Staff]);
    $agent->forceFill(['two_factor_confirmed_at' => now()])->save();
    $agent->assignRole('Support Agent');

    $this->actingAs($agent)
        ->post(adminUrl('/merchandising/campaigns/quick-start'))
        ->assertForbidden();
});

it('surfaces the most-searched recent terms as trending searches', function () {
    SearchTerm::query()->create(['term' => 'iphone', 'search_count' => 5, 'last_searched_at' => now()]);
    SearchTerm::query()->create(['term' => 'generator', 'search_count' => 2, 'last_searched_at' => now()]);
    // Searched a lot, but a year ago — should not crowd out what people are
    // actually looking for today.
    SearchTerm::query()->create(['term' => 'stale fad', 'search_count' => 999, 'last_searched_at' => now()->subYear()]);

    expect(app(HomeDataService::class)->trendingSearches())->toBe(['iphone', 'generator']);
});
