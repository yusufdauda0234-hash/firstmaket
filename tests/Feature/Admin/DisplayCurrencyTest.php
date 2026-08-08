<?php

use App\Models\User;
use App\Modules\Catalog\Models\DisplayCurrency;
use App\Modules\Catalog\Services\LocalePreference;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

/** Staff member carrying a role — same pattern as FeeSettingsTest. */
function currencyStaff(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function currencyUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/settings/currencies'
        .($path === '' ? '' : '/'.ltrim($path, '/'));
}

function baseNaira(): DisplayCurrency
{
    return DisplayCurrency::query()->create([
        'code' => 'NGN', 'symbol' => '₦', 'name' => 'Nigerian Naira',
        'units_per_naira' => '1', 'decimals' => 0, 'is_active' => true,
    ]);
}

it('lists currencies for staff who manage pricing', function () {
    baseNaira();

    $this->actingAs(currencyStaff('Administrator'))
        ->get(currencyUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Currencies')
            ->has('currencies', 1));
});

it('blocks staff without the permission', function () {
    baseNaira();

    $this->actingAs(currencyStaff('Support Agent'))
        ->get(currencyUrl())
        ->assertForbidden();
});

it('adds a currency and shows it to shoppers', function () {
    baseNaira();

    $this->actingAs(currencyStaff('Administrator'))
        ->post(currencyUrl(), [
            'code' => 'USD',
            'symbol' => '$',
            'name' => 'US Dollar',
            'units_per_naira' => '0.00065',
            'decimals' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect();

    $usd = DisplayCurrency::query()->firstWhere('code', 'USD');

    expect($usd)->not->toBeNull()
        ->and($usd->rate())->toBe(0.00065)
        ->and($usd->is_active)->toBeTrue();
});

it('rejects a rate of zero, which would price everything at nothing', function () {
    baseNaira();

    $this->actingAs(currencyStaff('Administrator'))
        ->post(currencyUrl(), [
            'code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar',
            'units_per_naira' => '0', 'decimals' => 2,
        ])
        ->assertSessionHasErrors('units_per_naira');

    expect(DisplayCurrency::query()->where('code', 'USD')->exists())->toBeFalse();
});

it('rejects a duplicate currency code', function () {
    baseNaira();

    $this->actingAs(currencyStaff('Administrator'))
        ->post(currencyUrl(), [
            'code' => 'NGN', 'symbol' => '₦', 'name' => 'Naira again',
            'units_per_naira' => '1', 'decimals' => 0,
        ])
        ->assertSessionHasErrors('code');
});

it('updates a rate and clears the cached list', function () {
    baseNaira();
    $usd = DisplayCurrency::query()->create([
        'code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar',
        'units_per_naira' => '0.00065', 'decimals' => 2, 'is_active' => true,
    ]);

    // Warm the cache so the test proves it gets invalidated.
    app(LocalePreference::class)->activeCurrencies();

    $this->actingAs(currencyStaff('Administrator'))
        ->put(currencyUrl((string) $usd->id), [
            'code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar',
            'units_per_naira' => '0.00058', 'decimals' => 2, 'is_active' => true,
        ])
        ->assertRedirect();

    expect(app(LocalePreference::class)->activeCurrencies()->firstWhere('code', 'USD')->rate())
        ->toBe(0.00058);
});

it('protects the naira: its rate stays 1 and it cannot be hidden', function () {
    $ngn = baseNaira();

    // Every price is stored in kobo and settled in naira, so a rate other than
    // 1 — or hiding it — would corrupt the whole storefront.
    $this->actingAs(currencyStaff('Administrator'))
        ->put(currencyUrl((string) $ngn->id), [
            'code' => 'NGN', 'symbol' => '₦', 'name' => 'Nigerian Naira',
            'units_per_naira' => '7.5', 'decimals' => 0, 'is_active' => false,
        ])
        ->assertRedirect();

    $ngn->refresh();

    expect($ngn->rate())->toBe(1.0)
        ->and($ngn->is_active)->toBeTrue();
});

it('refuses to delete the naira', function () {
    $ngn = baseNaira();

    $this->actingAs(currencyStaff('Administrator'))
        ->delete(currencyUrl((string) $ngn->id))
        ->assertRedirect();

    expect(DisplayCurrency::query()->whereKey($ngn->id)->exists())->toBeTrue();
});

it('deletes a non-base currency', function () {
    baseNaira();
    $usd = DisplayCurrency::query()->create([
        'code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar',
        'units_per_naira' => '0.00065', 'decimals' => 2, 'is_active' => true,
    ]);

    $this->actingAs(currencyStaff('Administrator'))
        ->delete(currencyUrl((string) $usd->id))
        ->assertRedirect();

    expect(DisplayCurrency::query()->whereKey($usd->id)->exists())->toBeFalse();
});
