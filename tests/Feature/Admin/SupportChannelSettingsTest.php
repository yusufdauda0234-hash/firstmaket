<?php

use App\Models\Setting;
use App\Models\User;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Phase 2C live-chat configuration.
 *
 * The security point is the one worth testing: the widget is configured by
 * naming a known provider and supplying an id, never by pasting a snippet.
 * Anything that would let arbitrary script reach the storefront — where
 * customers type card details — must be refused.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create(['user_type' => UserType::Staff]);
    $this->admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->admin->assignRole('Super Administrator');
});

it('ships with chat switched off', function () {
    expect(Setting::get('support.chat_provider', 'none'))->toBe('none');
});

it('saves a provider and its id', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/settings/support-channels'), [
            'chat_provider' => 'tawk',
            'chat_property_id' => '6512ab34cd',
            'chat_widget_id' => 'default',
            'chat_for_guests' => true,
        ])
        ->assertRedirect();

    Setting::flushCache();

    expect(Setting::get('support.chat_provider'))->toBe('tawk')
        ->and(Setting::get('support.chat_property_id'))->toBe('6512ab34cd');
});

it('refuses a provider it does not know how to embed', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/settings/support-channels'), [
            'chat_provider' => 'somebody-elses-script',
            'chat_property_id' => 'abc',
            'chat_widget_id' => '',
            'chat_for_guests' => true,
        ])
        ->assertSessionHasErrors('chat_provider');
});

it('refuses anything that looks like markup in the id', function () {
    // The whole reason the id is validated: this is a storefront where
    // customers enter payment details.
    $this->actingAs($this->admin)
        ->post(adminUrl('/settings/support-channels'), [
            'chat_provider' => 'crisp',
            'chat_property_id' => '"><script>alert(1)</script>',
            'chat_widget_id' => '',
            'chat_for_guests' => true,
        ])
        ->assertSessionHasErrors('chat_property_id');

    Setting::flushCache();
    expect(Setting::get('support.chat_provider', 'none'))->toBe('none');
});

it('shares the chat config with the storefront but not the staff portal', function () {
    Setting::set('support.chat_provider', 'crisp', 'support');
    Setting::set('support.chat_property_id', 'abc123', 'support');

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('supportChat.provider', 'crisp'));

    $this->actingAs($this->admin)
        ->get(adminUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('supportChat', null));
});

it('keeps the settings screen away from staff without settings.manage', function () {
    $agent = User::factory()->create(['user_type' => UserType::Staff]);
    $agent->forceFill(['two_factor_confirmed_at' => now()])->save();
    $agent->assignRole('Support Agent');

    $this->actingAs($agent)
        ->get(adminUrl('/settings/support-channels'))
        ->assertForbidden();
});
