<?php

use App\Models\User;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The manual phone-verification queue.
 *
 * This screen had no tests at all, and shipped a 404 on its only two buttons:
 * the page posted a numeric id while the route bound by uuid, so approving
 * anybody looked for a user whose uuid was "2". Everything below exists
 * because a page nobody exercises is a page nobody knows is broken.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->admin = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Administrator');
});

function phoneUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/phone-numbers'.$path;
}

/** Somebody waiting on a human to confirm their number. */
function awaitingPhoneReview(string $phone = '08031234567'): User
{
    $user = User::factory()->create(['phone' => $phone, 'phone_verified_at' => null]);
    $user->assignRole('Customer');

    return $user;
}

it('lists accounts waiting on a number check', function () {
    $waiting = awaitingPhoneReview();
    User::factory()->create(['phone' => '08099999999', 'phone_verified_at' => now()]);

    $this->actingAs($this->admin)
        ->get(phoneUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Phone/Index')
            ->has('users.data', 1)
            ->where('users.data.0.uuid', $waiting->uuid));
});

it('sends the uuid the route actually binds on', function () {
    // The page used to be given a numeric id. HasUuid makes getRouteKeyName
    // return 'uuid', so every approve button built a URL that could never
    // match — /phone-numbers/2/approve looked for uuid "2" and 404'd.
    $waiting = awaitingPhoneReview();

    $this->actingAs($this->admin)
        ->get(phoneUrl())
        ->assertInertia(fn ($page) => $page->where('users.data.0.uuid', $waiting->uuid));
});

it('approves a number', function () {
    $waiting = awaitingPhoneReview();

    $this->actingAs($this->admin)
        ->post(phoneUrl('/'.$waiting->uuid.'/approve'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($waiting->fresh()->phone_verified_at)->not->toBeNull();
});

it('rejects a number with a reason', function () {
    $waiting = awaitingPhoneReview();

    $this->actingAs($this->admin)
        ->post(phoneUrl('/'.$waiting->uuid.'/reject'), ['reason' => 'Number belongs to someone else'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($waiting->fresh()->phone_verified_at)->toBeNull();
});

it('refuses to approve an account with no number', function () {
    $noPhone = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

    $this->actingAs($this->admin)
        ->post(phoneUrl('/'.$noPhone->uuid.'/approve'))
        ->assertSessionHasErrors('phone');
});

it('404s on a uuid that does not exist', function () {
    $this->actingAs($this->admin)
        ->post(phoneUrl('/no-such-uuid/approve'))
        ->assertNotFound();
});

it('is closed to staff without the identity permission', function () {
    $finance = User::factory()->create(['user_type' => UserType::Staff, 'two_factor_confirmed_at' => now()]);
    $finance->assignRole('Finance Officer');

    $waiting = awaitingPhoneReview();

    $this->actingAs($finance)->get(phoneUrl())->assertForbidden();
    $this->actingAs($finance)->post(phoneUrl('/'.$waiting->uuid.'/approve'))->assertForbidden();

    expect($waiting->fresh()->phone_verified_at)->toBeNull();
});

it('drops an account off the queue once approved', function () {
    $waiting = awaitingPhoneReview();

    $this->actingAs($this->admin)->post(phoneUrl('/'.$waiting->uuid.'/approve'));

    $this->actingAs($this->admin)
        ->get(phoneUrl())
        ->assertInertia(fn ($page) => $page->has('users.data', 0));
});
