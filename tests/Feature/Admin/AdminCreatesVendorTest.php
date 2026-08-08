<?php

use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Notifications\VendorPasswordResetNotification;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function staffCreateVendorUrl(): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/vendors';
}

function vendorCreatingStaff(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

/** @return array<string, mixed> */
function staffVendorPayload(array $overrides = []): array
{
    return [
        'business_name' => 'Bright Electronics Ltd',
        'contact_name' => 'Chinedu Okafor',
        'email' => 'sales@brightelectronics.test',
        'phone' => '08031234567',
        'address' => '14 Ahmadu Bello Way, Kaduna',
        'approve_now' => false,
        ...$overrides,
    ];
}

it('creates a vendor account and profile', function () {
    Notification::fake();

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), staffVendorPayload())
        ->assertRedirect();

    $user = User::query()->firstWhere('email', 'sales@brightelectronics.test');

    expect($user)->not->toBeNull()
        ->and($user->user_type)->toBe(UserType::Vendor)
        ->and($user->hasRole('Vendor'))->toBeTrue()
        // Staff vouching for the account stands in for the inbox round-trip.
        ->and($user->email_verified_at)->not->toBeNull();

    $profile = VendorProfile::query()->firstWhere('user_id', $user->id);

    expect($profile)->not->toBeNull()
        ->and($profile->business_name)->toBe('Bright Electronics Ltd')
        ->and($profile->status)->toBe(VendorStatus::Pending);
});

it('emails a set-your-password link instead of assigning a password', function () {
    Notification::fake();

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), staffVendorPayload())
        ->assertRedirect();

    $user = User::query()->firstWhere('email', 'sales@brightelectronics.test');

    // No member of staff ever knows a vendor's password: the new account gets a
    // one-time link straight into the Vendor Center and chooses its own.
    Notification::assertSentTo($user, VendorPasswordResetNotification::class);

    expect($user->password)->not->toBeEmpty();
});

it('actually renders the password email, not just queues it', function () {
    // Notification::fake() short-circuits before a notification builds its
    // message, which is exactly how a RouteNotFoundException in that message
    // reached production unnoticed. Faking only the mail transport lets the
    // notification render for real, so a broken route fails the test.
    Mail::fake();

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), staffVendorPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'sales@brightelectronics.test')->exists())->toBeTrue();
});

it('approves immediately when asked, through the approval service', function () {
    Notification::fake();

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), staffVendorPayload(['approve_now' => true]))
        ->assertRedirect();

    $profile = VendorProfile::query()->firstWhere('business_name', 'Bright Electronics Ltd');

    // approved_at is not fillable, so its presence proves the transition went
    // through VendorApprovalService rather than a direct write.
    expect($profile->status)->toBe(VendorStatus::Approved)
        ->and($profile->approved_at)->not->toBeNull()
        ->and($profile->approved_by)->not->toBeNull();
});

it('stores an attached CAC document on the private disk', function () {
    Notification::fake();
    Storage::fake('local');

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), staffVendorPayload([
            'cac_document' => UploadedFile::fake()->create('cac.pdf', 200, 'application/pdf'),
        ]))
        ->assertRedirect();

    $profile = VendorProfile::query()->firstWhere('business_name', 'Bright Electronics Ltd');

    expect($profile->documents)->toHaveCount(1)
        ->and($profile->documents->first()->disk)->toBe('local');
});

it('works without a CAC document, unlike public sign-up', function () {
    Notification::fake();

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), staffVendorPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(VendorProfile::query()->where('business_name', 'Bright Electronics Ltd')->exists())->toBeTrue();
});

it('rejects an email that already has an account', function () {
    Notification::fake();
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), staffVendorPayload(['email' => 'taken@example.test']))
        ->assertSessionHasErrors('email');

    expect(VendorProfile::query()->count())->toBe(0);
});

it('requires the business name, contact and address', function () {
    Notification::fake();

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), ['email' => 'someone@example.test'])
        ->assertSessionHasErrors(['business_name', 'contact_name', 'address']);
});

it('rejects an executable disguised as a CAC document', function () {
    Notification::fake();
    Storage::fake('local');

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post(staffCreateVendorUrl(), staffVendorPayload([
            'cac_document' => UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload'),
        ]))
        ->assertSessionHasErrors('cac_document');
});

it('blocks staff who cannot approve vendors', function () {
    Notification::fake();

    // Creating a seller outright is at least as privileged as approving one.
    $this->actingAs(vendorCreatingStaff('Support Agent'))
        ->post(staffCreateVendorUrl(), staffVendorPayload())
        ->assertForbidden();

    expect(VendorProfile::query()->count())->toBe(0);
});

it('is not reachable from the customer site', function () {
    Notification::fake();

    $this->actingAs(vendorCreatingStaff('Administrator'))
        ->post('http://'.strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'/vendors',
            staffVendorPayload())
        ->assertNotFound();
});
