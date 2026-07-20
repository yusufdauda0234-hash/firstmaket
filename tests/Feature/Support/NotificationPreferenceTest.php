<?php

use App\Models\User;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Services\NotificationPreferenceService;
use App\Modules\Orders\Notifications\OrderStatusNotification;
use App\Shared\Enums\NotificationCategory;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 7 QA (docs/firstmarket_Implementation_Plan.md): notification
 * preferences are respected per category, the in-app inbox works, and the
 * security email toggle is locked on.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
});

it('defaults to email + in-app on and SMS off', function () {
    $channels = app(NotificationPreferenceService::class)
        ->channelsFor($this->customer, NotificationCategory::Orders);

    expect($channels)->toBe(['mail', 'database']);
});

it('respects per-category preference toggles', function () {
    $this->actingAs($this->customer)->put(route('notifications.preferences.update'), [
        'category' => 'orders',
        'email_enabled' => false,
        'sms_enabled' => true,
        'browser_enabled' => true,
    ])->assertRedirect();

    $channels = app(NotificationPreferenceService::class)
        ->channelsFor($this->customer, NotificationCategory::Orders);

    expect($channels)->toBe(['sms', 'database'])
        // Other categories keep their defaults.
        ->and(app(NotificationPreferenceService::class)->channelsFor($this->customer, NotificationCategory::Savings))
        ->toBe(['mail', 'database']);
});

it('never disables security email even when asked to', function () {
    $this->actingAs($this->customer)->put(route('notifications.preferences.update'), [
        'category' => 'security',
        'email_enabled' => false,
        'sms_enabled' => false,
        'browser_enabled' => false,
    ])->assertRedirect();

    expect(NotificationPreference::query()
        ->where('user_id', $this->customer->id)
        ->where('category', 'security')
        ->first()->email_enabled)->toBeTrue();

    expect(app(NotificationPreferenceService::class)->channelsFor($this->customer, NotificationCategory::Security))
        ->toContain('mail');
});

it('skips SMS for users without a verified phone', function () {
    $unverified = User::factory()->create(['phone_verified_at' => null]);
    $unverified->assignRole('Customer');

    NotificationPreference::query()->create([
        'user_id' => $unverified->id,
        'category' => 'orders',
        'email_enabled' => true,
        'sms_enabled' => true,
        'browser_enabled' => true,
    ]);

    expect(app(NotificationPreferenceService::class)->channelsFor($unverified, NotificationCategory::Orders))
        ->toBe(['mail', 'database']);
});

it('delivers in-app notifications to the inbox and marks them read', function () {
    // Send synchronously so the database row exists immediately.
    $this->customer->notifyNow(new OrderStatusNotification('ORD-123', 'Solar Generator', 'Shipped'));

    $response = $this->actingAs($this->customer)->get(route('notifications.index'))->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['unreadCount'])->toBe(1)
        ->and($props['notifications'][0]['title'])->toContain('Shipped');

    $notificationId = $props['notifications'][0]['id'];

    $this->actingAs($this->customer)
        ->post(route('notifications.read', $notificationId))
        ->assertRedirect();

    expect($this->customer->unreadNotifications()->count())->toBe(0);
});

it('records notification deliveries for monitoring', function () {
    $this->customer->notifyNow(new OrderStatusNotification('ORD-123', 'Solar Generator', 'Shipped'));

    // mail + database (defaults) each get a delivery row.
    $this->assertDatabaseHas('notification_deliveries', [
        'user_id' => $this->customer->id,
        'channel' => 'email',
        'status' => 'sent',
    ]);
    $this->assertDatabaseHas('notification_deliveries', [
        'user_id' => $this->customer->id,
        'channel' => 'browser',
        'status' => 'sent',
    ]);
});
