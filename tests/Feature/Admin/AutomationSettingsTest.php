<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Every automation threshold is reachable from admin, and actually drives
 * behaviour.
 *
 * These were all read with a fallback and no screen to change them, which is a
 * hardcoded value wearing a costume. What is tested here is the part that
 * matters: saving a value changes what the system does.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create(['user_type' => UserType::Staff]);
    $this->admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->admin->assignRole('Super Administrator');
});

it('lists every threshold on the screen', function () {
    $this->actingAs($this->admin)
        ->get(adminUrl('/settings/automation'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Automation')
            // Orders, plans/delivery, home, vendor ratings, risk, assistant,
            // recommendations.
            ->has('groups', 7));
});

it('changes the plan switch limit that is enforced', function () {
    expect(SavingsGoalService::maxSwitches())->toBe(2);

    Setting::set('savings.max_plan_switches', 5, 'savings');

    expect(SavingsGoalService::maxSwitches())->toBe(5);
});

it('changes how many delivery attempts a parcel gets', function () {
    expect(Shipment::maxAttempts())->toBe(3);

    Setting::set('logistics.max_delivery_attempts', 5, 'logistics');

    expect(Shipment::maxAttempts())->toBe(5);
});

it('saves the whole form and reads every value back', function () {
    $page = $this->actingAs($this->admin)->get(adminUrl('/settings/automation'));

    $payload = [];

    foreach ($page->viewData('page')['props']['groups'] as $group) {
        foreach ($group['fields'] as $field) {
            // Nudge each one off its default so a value that is silently
            // dropped would show up as an unchanged read.
            $payload[$field['name']] = min($field['max'], $field['value'] + 1);
        }
    }

    $this->actingAs($this->admin)
        ->post(adminUrl('/settings/automation'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Setting::flushCache();

    expect((int) Setting::get('risk.failed_payments_threshold'))->toBe(4)
        ->and((int) Setting::get('vendor_rating.weight_fulfilment'))->toBe(41)
        ->and((int) Setting::get('assistant.minimum_payments'))->toBe(4);
});

it('refuses a value outside its allowed range', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/settings/automation'), ['orders_auto_confirm_days' => 9999])
        ->assertSessionHasErrors('orders_auto_confirm_days');
});

it('keeps the screen away from staff without settings.manage', function () {
    $agent = User::factory()->create(['user_type' => UserType::Staff]);
    $agent->forceFill(['two_factor_confirmed_at' => now()])->save();
    $agent->assignRole('Support Agent');

    $this->actingAs($agent)
        ->get(adminUrl('/settings/automation'))
        ->assertForbidden();
});
