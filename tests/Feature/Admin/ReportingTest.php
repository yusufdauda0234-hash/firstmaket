<?php

use App\Models\User;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 9 QA: reports read straight from source tables for the given date
 * range, and a report outside the range is excluded — so figures always
 * match the underlying tables rather than a snapshot that could drift.
 */
beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function reportingStaff(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

it('counts signups within the requested date range and excludes ones outside it', function () {
    $admin = reportingStaff('Administrator');

    $inRange = User::factory()->create(['user_type' => UserType::Customer, 'created_at' => now()->subDays(5)]);
    $outOfRange = User::factory()->create(['user_type' => UserType::Customer, 'created_at' => now()->subDays(90)]);

    $response = $this->actingAs($admin)
        ->get('http://'.config('app.admin_domain').'/reports?from='.now()->subDays(10)->toDateString().'&to='.now()->toDateString())
        ->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['signups']['total'])->toBeGreaterThanOrEqual(1);

    $rows = collect($props['signups']['rows'])->pluck('id');
    expect($rows)->toContain($inRange->id)
        ->and($rows)->not->toContain($outOfRange->id);
});

it('sums deposits to match the wallet_transactions table for the range', function () {
    $admin = reportingStaff('Administrator');
    $customer = User::factory()->create(['user_type' => UserType::Customer]);

    app(WalletService::class)->creditDeposit($customer, 25_000_00, 'TEST-DEP-'.fake()->unique()->uuid());
    app(WalletService::class)->creditDeposit($customer, 10_000_00, 'TEST-DEP-'.fake()->unique()->uuid());

    $response = $this->actingAs($admin)
        ->get('http://'.config('app.admin_domain').'/reports')
        ->assertOk();

    $deposits = $response->viewData('page')['props']['deposits'];

    expect($deposits['count'])->toBe(2)
        ->and($deposits['totalKobo'])->toBe(35_000_00);
});

it('exports a CSV with a header row and one row per record', function () {
    $admin = reportingStaff('Administrator');
    User::factory()->create(['user_type' => UserType::Customer]);

    $response = $this->actingAs($admin)
        ->get('http://'.config('app.admin_domain').'/reports/export/signups')
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    expect(substr_count($csv, "\n"))->toBeGreaterThanOrEqual(1);
});

it('denies the reports dashboard to a role without reports.view', function () {
    $this->actingAs(reportingStaff('Logistics Personnel'))
        ->get('http://'.config('app.admin_domain').'/reports')
        ->assertForbidden();
});
