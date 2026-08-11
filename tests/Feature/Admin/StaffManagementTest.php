<?php

use App\Models\User;
use App\Modules\Admin\Notifications\StaffPasswordResetNotification;
use App\Modules\Logistics\Models\CourierProfile;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Creating staff — the gap that made hiring a courier a developer task.
 *
 * Behind its own permission, because creating a staff account is creating a
 * way into the admin domain. Nobody sets anybody else's password.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function staffAdmin(string $role = 'Super Administrator'): User
{
    $user = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function staffUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/staff'.$path;
}

function courierPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Musa Ibrahim',
        'email' => 'musa@firstmaket.test',
        'phone' => '08031234567',
        'role' => 'Logistics Personnel',
        'vehicle_type' => 'motorcycle',
        'vehicle_plate' => 'ABC123XY',
        'base_state' => 'Gombe',
        'max_open_shipments' => 10,
    ], $overrides);
}

// ── Who may do it ───────────────────────────────────────────────────────

it('shows the staff screen to a super administrator', function () {
    $this->actingAs(staffAdmin())
        ->get(staffUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Staff/Index'));
});

it('keeps a support agent out', function () {
    // Support can see customers; making colleagues is a different thing.
    $this->actingAs(staffAdmin('Support Agent'))->get(staffUrl())->assertForbidden();
});

it('keeps a courier out', function () {
    $this->actingAs(staffAdmin('Logistics Personnel'))->get(staffUrl())->assertForbidden();
});

it('keeps a customer off the page entirely', function () {
    $customer = User::factory()->create();
    $customer->assignRole('Customer');

    // 302, not 403: EnsureCorrectPortal moves a customer off the admin domain
    // before any permission is consulted.
    $this->actingAs($customer)->get(staffUrl())->assertStatus(302);
});

// ── Creating ────────────────────────────────────────────────────────────

it('creates a courier with a vehicle and puts them on the dispatch list', function () {
    $this->actingAs(staffAdmin())
        ->post(staffUrl(), courierPayload())
        ->assertRedirect();

    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    expect($courier->user_type)->toBe(UserType::Staff)
        ->and($courier->hasRole('Logistics Personnel'))->toBeTrue()
        ->and($courier->status)->toBe(UserStatus::Active)
        ->and($courier->courierProfile)->not->toBeNull()
        ->and($courier->courierProfile->vehicle_type->value)->toBe('motorcycle')
        ->and($courier->courierProfile->is_available)->toBeTrue();
});

it('emails the new staff member a link to set their password', function () {
    // The message said "we have emailed them" whether or not anything was
    // sent — and once it was not: every test here asserted the row in the
    // database and none asserted the email, so a screen that silently sent
    // nothing passed for a full suite.
    //
    // It used to be a six-digit code, which was worse than useless: the admin
    // portal has nowhere to type one, so a new joiner was left holding a
    // number and no way to use it.
    $this->actingAs(staffAdmin())
        ->post(staffUrl(), courierPayload())
        ->assertRedirect()
        ->assertSessionHas('success');

    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    Notification::assertSentTo($courier, StaffPasswordResetNotification::class);
});

it('says so plainly when the email could not be sent', function () {
    // The account still exists, so this is not a failed creation — but it is
    // not a success either, and the admin has to know to resend the link.
    Notification::shouldReceive('send')
        ->andThrow(new RuntimeException('Mail server refused the connection'));

    $this->actingAs(staffAdmin())
        ->post(staffUrl(), courierPayload())
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(User::query()->where('email', 'musa@firstmaket.test')->exists())->toBeTrue();
});

it('resends the password link on request', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload());
    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    Notification::fake();

    $this->actingAs(staffAdmin())
        ->post(staffUrl('/'.$courier->uuid.'/password-link'))
        ->assertRedirect()
        ->assertSessionHas('success');

    // A link, not a six-digit code: the admin portal has no screen to type a
    // code into, so the code was a dead end for whoever received it.
    Notification::assertSentTo($courier, StaffPasswordResetNotification::class);
});

it('never lets an admin choose somebody else\'s password', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload(['password' => 'letmein']));

    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    // The account gets an unguessable secret and the staff member sets their
    // own from an emailed code. Staff must never know each other's password.
    expect(Hash::check('letmein', $courier->password))->toBeFalse();
});

it('creates a non-courier without a courier profile', function () {
    $this->actingAs(staffAdmin())
        ->post(staffUrl(), courierPayload([
            'role' => 'Finance Officer',
            'email' => 'finance@firstmaket.test',
        ]))
        ->assertRedirect();

    $officer = User::query()->where('email', 'finance@firstmaket.test')->firstOrFail();

    expect($officer->hasRole('Finance Officer'))->toBeTrue()
        ->and($officer->courierProfile)->toBeNull();
});

it('demands a phone number for a courier', function () {
    // A courier reachable only by email is no use at a locked gate.
    $this->actingAs(staffAdmin())
        ->post(staffUrl(), courierPayload(['phone' => null]))
        ->assertSessionHasErrors('phone');

    expect(User::query()->where('email', 'musa@firstmaket.test')->exists())->toBeFalse();
});

it('refuses a duplicate email', function () {
    User::factory()->create(['email' => 'musa@firstmaket.test']);

    $this->actingAs(staffAdmin())
        ->post(staffUrl(), courierPayload())
        ->assertSessionHasErrors('email');
});

it('refuses a role it is not allowed to hand out', function () {
    // Super Administrator bypasses every permission check via Gate::before.
    // Granting it is not an ordinary staffing decision.
    $this->actingAs(staffAdmin())
        ->post(staffUrl(), courierPayload(['role' => 'Super Administrator']))
        ->assertSessionHasErrors('role');
});

it('refuses a malformed phone number', function () {
    $this->actingAs(staffAdmin())
        ->post(staffUrl(), courierPayload(['phone' => '12345']))
        ->assertSessionHasErrors('phone');
});

// ── Editing ─────────────────────────────────────────────────────────────

it('changes a role and drops the courier profile out of use', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload());
    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    $this->actingAs(staffAdmin())
        ->put(staffUrl('/'.$courier->uuid), courierPayload(['role' => 'Support Agent']))
        ->assertRedirect();

    expect($courier->fresh()->hasRole('Support Agent'))->toBeTrue()
        ->and($courier->fresh()->hasRole('Logistics Personnel'))->toBeFalse();
});

// ── Suspending and availability ─────────────────────────────────────────

it('suspends a courier and takes them off the dispatch list', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload());
    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    $this->actingAs(staffAdmin())
        ->post(staffUrl('/'.$courier->uuid.'/suspend'), ['reason' => 'Left the company'])
        ->assertRedirect();

    // Both, or a suspended account keeps being offered parcels.
    expect($courier->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($courier->fresh()->courierProfile->is_available)->toBeFalse();
});

it('will not let an admin suspend themselves', function () {
    $admin = staffAdmin();

    $this->actingAs($admin)
        ->post(staffUrl('/'.$admin->uuid.'/suspend'), [])
        ->assertSessionHasErrors('staff');

    expect($admin->fresh()->status)->toBe(UserStatus::Active);
});

it('never deletes a staff row', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload());
    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    $this->actingAs(staffAdmin())->post(staffUrl('/'.$courier->uuid.'/suspend'), []);

    // The audit trail and every delivery they carried point at this row.
    expect(User::query()->whereKey($courier->id)->exists())->toBeTrue();
});

it('takes a courier off the dispatch list without suspending them', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload());
    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    $this->actingAs(staffAdmin())
        ->post(staffUrl('/'.$courier->uuid.'/availability'))
        ->assertRedirect();

    // A day off is not a disciplinary record.
    expect($courier->fresh()->courierProfile->is_available)->toBeFalse()
        ->and($courier->fresh()->status)->toBe(UserStatus::Active);
});

it('reactivates a suspended courier back onto the dispatch list', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload());
    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    $this->actingAs(staffAdmin())->post(staffUrl('/'.$courier->uuid.'/suspend'), []);
    $this->actingAs(staffAdmin())->post(staffUrl('/'.$courier->uuid.'/reactivate'));

    expect($courier->fresh()->status)->toBe(UserStatus::Active)
        ->and($courier->fresh()->courierProfile->is_available)->toBeTrue();
});

// ── The listing ─────────────────────────────────────────────────────────

it('lists staff only, never customers', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload());
    User::factory()->create(['name' => 'Shopper Person'])->assignRole('Customer');

    $this->actingAs(staffAdmin())
        ->get(staffUrl())
        ->assertInertia(fn ($page) => $page->where(
            'staff.data',
            fn ($rows) => collect($rows)->doesntContain(fn ($row) => $row['name'] === 'Shopper Person'),
        ));
});

it('reports how much a courier is carrying', function () {
    $this->actingAs(staffAdmin())->post(staffUrl(), courierPayload());
    $courier = User::query()->where('email', 'musa@firstmaket.test')->firstOrFail();

    expect(CourierProfile::query()->where('user_id', $courier->id)->firstOrFail()->openShipmentCount())
        ->toBe(0);
});
