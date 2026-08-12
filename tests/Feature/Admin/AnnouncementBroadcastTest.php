<?php

use App\Models\User;
use App\Modules\Notifications\Jobs\SendAnnouncementJob;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Notifications\AnnouncementNotification;
use App\Modules\Notifications\Services\AnnouncementService;
use App\Modules\Notifications\Services\NotificationPreferenceService;
use App\Shared\Enums\NotificationCategory;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function announcementUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/notifications'.$path;
}

function announcementSender(string $role = 'Administrator'): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

/** @return array<string, mixed> */
function announcementPayload(array $overrides = []): array
{
    return [
        'title' => 'Delivery delays in Lagos',
        'body' => 'Heavy rain is slowing deliveries in Lagos this week. Orders may arrive a day late.',
        'audience' => Announcement::AUDIENCE_ALL,
        'channels' => ['database'],
        'category' => NotificationCategory::Orders->value,
        ...$overrides,
    ];
}

it('sends a broadcast to every active user', function () {
    Queue::fake();

    User::factory()->count(3)->create();
    $sender = announcementSender();

    $this->actingAs($sender)
        ->post(announcementUrl(), announcementPayload())
        ->assertRedirect();

    $announcement = Announcement::query()->sole();

    // The sender counts too — they are an active account like any other.
    expect($announcement->recipients_count)->toBe(4)
        ->and($announcement->audience)->toBe(Announcement::AUDIENCE_ALL)
        ->and($announcement->sent_by)->toBe($sender->id)
        ->and($announcement->sent_at)->not->toBeNull();

    Queue::assertPushed(SendAnnouncementJob::class);
});

it('sends only to the chosen role', function () {
    Queue::fake();

    $customers = User::factory()->count(2)->create();
    $customers->each(fn (User $user) => $user->assignRole('Customer'));
    User::factory()->count(3)->create(); // No role — must not be counted.

    $sender = announcementSender();
    $customerRoleId = Role::query()->where('name', 'Customer')->value('id');

    $this->actingAs($sender)
        ->post(announcementUrl(), announcementPayload([
            'audience' => Announcement::AUDIENCE_ROLE,
            'role_id' => $customerRoleId,
        ]))
        ->assertRedirect();

    expect(Announcement::query()->sole()->recipients_count)->toBe(2);
});

it('sends to a single person', function () {
    Queue::fake();

    $target = User::factory()->create();
    User::factory()->count(4)->create();

    $this->actingAs(announcementSender())
        ->post(announcementUrl(), announcementPayload([
            'audience' => Announcement::AUDIENCE_USER,
            'user_id' => $target->id,
        ]))
        ->assertRedirect();

    $announcement = Announcement::query()->sole();

    expect($announcement->recipients_count)->toBe(1)
        ->and($announcement->user_id)->toBe($target->id);
});

it('never sends to suspended or banned accounts', function () {
    Queue::fake();

    User::factory()->create(['status' => UserStatus::Active]);
    User::factory()->create(['status' => UserStatus::Suspended]);
    User::factory()->create(['status' => UserStatus::Banned]);

    $this->actingAs(announcementSender())
        ->post(announcementUrl(), announcementPayload())
        ->assertRedirect();

    // The active user plus the sender. The shut-out accounts are skipped:
    // they cannot sign in to read it, and mailing them invites a complaint.
    expect(Announcement::query()->sole()->recipients_count)->toBe(2);
});

it('refuses to send to an empty audience', function () {
    Queue::fake();

    $sender = announcementSender();
    $emptyRoleId = Role::query()->where('name', 'Logistics Personnel')->value('id');

    $this->actingAs($sender)
        ->post(announcementUrl(), announcementPayload([
            'audience' => Announcement::AUDIENCE_ROLE,
            'role_id' => $emptyRoleId,
        ]))
        ->assertSessionHasErrors('audience');

    expect(Announcement::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('clears the role when the audience is everyone', function () {
    Queue::fake();

    $roleId = Role::query()->where('name', 'Customer')->value('id');
    $sender = announcementSender();

    $this->actingAs($sender)
        ->post(announcementUrl(), announcementPayload([
            'audience' => Announcement::AUDIENCE_ALL,
            'role_id' => $roleId,
            'user_id' => $sender->id,
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $announcement = Announcement::query()->sole();

    // A stale role id would make the sent list claim it went somewhere it
    // did not.
    expect($announcement->role_id)->toBeNull()
        ->and($announcement->user_id)->toBeNull();
});

it('delivers down the channels the sender chose', function () {
    NotificationFacade::fake();

    $recipient = User::factory()->create(['phone_verified_at' => now()]);
    app(NotificationPreferenceService::class)->update(
        user: $recipient,
        category: NotificationCategory::Orders,
        email: true,
        sms: true,
        browser: true,
    );

    $announcement = Announcement::query()->create([
        'title' => 'Test',
        'body' => 'Body',
        'audience' => Announcement::AUDIENCE_USER,
        'user_id' => $recipient->id,
        'channels' => ['mail'],
        'category' => NotificationCategory::Orders->value,
    ]);

    app(AnnouncementService::class)->deliver($announcement);

    NotificationFacade::assertSentTo(
        $recipient,
        AnnouncementNotification::class,
        // Everything is enabled for this person, so the narrowing here is
        // entirely the sender's choice of channel.
        fn (AnnouncementNotification $notification, array $channels) => $channels === ['mail'],
    );
});

it('respects a recipient who has switched the channel off', function () {
    NotificationFacade::fake();

    $recipient = User::factory()->create(['phone_verified_at' => now()]);
    app(NotificationPreferenceService::class)->update(
        user: $recipient,
        category: NotificationCategory::Promotions,
        email: false,
        sms: false,
        browser: true,
    );

    $announcement = Announcement::query()->create([
        'title' => 'Half price today',
        'body' => 'Everything must go.',
        'audience' => Announcement::AUDIENCE_USER,
        'user_id' => $recipient->id,
        // The sender asked for all three.
        'channels' => ['database', 'mail', 'sms'],
        'category' => NotificationCategory::Promotions->value,
    ]);

    app(AnnouncementService::class)->deliver($announcement);

    NotificationFacade::assertSentTo(
        $recipient,
        AnnouncementNotification::class,
        // An admin can send down fewer channels than somebody has switched
        // on, never more. Turning off promotional email must not be undone
        // by a broadcast that ticks the box.
        fn (AnnouncementNotification $notification, array $channels) => $channels === ['database'],
    );
});

it('is closed to staff without the permission', function () {
    Queue::fake();

    $this->actingAs(announcementSender('Logistics Personnel'))
        ->post(announcementUrl(), announcementPayload())
        ->assertForbidden();

    expect(Announcement::query()->count())->toBe(0);
});

it('is closed to a signed-out visitor', function () {
    $this->post(announcementUrl(), announcementPayload())->assertRedirect();

    expect(Announcement::query()->count())->toBe(0);
});
