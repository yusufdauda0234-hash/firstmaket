<?php

use App\Models\User;
use App\Modules\Auth\Models\SocialAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;

/**
 * Continue with Google/Facebook (Sprint 2 Addendum).
 */
beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function fakeOAuthUser(string $id = 'google-123', ?string $email = 'ada@example.com', string $name = 'Ada Lovelace'): OAuthUser
{
    $user = new OAuthUser;
    $user->map(['id' => $id, 'name' => $name, 'email' => $email, 'avatar' => 'https://example.com/a.png']);

    return $user;
}

function mockSocialite(OAuthUser $oauthUser, string $provider = 'google'): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($oauthUser);

    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

it('creates a customer with a verified email on first Google sign-in', function () {
    mockSocialite(fakeOAuthUser());

    $this->get('/auth/google/callback')->assertRedirect(route('home'));

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect($user->hasRole('Customer'))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->customerProfile)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-123',
    ]);
});

it('signs the same user in on a repeat Google sign-in without duplicating accounts', function () {
    mockSocialite(fakeOAuthUser());
    $this->get('/auth/google/callback');

    $this->post('/logout');

    mockSocialite(fakeOAuthUser());
    $this->get('/auth/google/callback')->assertRedirect(route('home'));

    expect(User::query()->where('email', 'ada@example.com')->count())->toBe(1)
        ->and(SocialAccount::query()->count())->toBe(1);
});

it('links Google to an existing account with the same email and verifies it', function () {
    $existing = User::factory()->unverified()->create(['email' => 'ada@example.com']);
    $existing->assignRole('Customer');

    mockSocialite(fakeOAuthUser());

    $this->get('/auth/google/callback')->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($existing);
    expect($existing->refresh()->email_verified_at)->not->toBeNull()
        ->and(SocialAccount::query()->where('user_id', $existing->id)->count())->toBe(1)
        ->and(User::query()->where('email', 'ada@example.com')->count())->toBe(1);
});

it('never signs a staff account in through social login', function () {
    $staff = User::factory()->staff()->create(['email' => 'admin@FirstMaket.ng']);

    mockSocialite(fakeOAuthUser(email: 'admin@FirstMaket.ng'));

    $this->get('/auth/google/callback')->assertRedirect(route('home'));

    $this->assertGuest();
    expect(SocialAccount::query()->count())->toBe(0);
});

it('rejects unsupported providers', function () {
    $this->get('/auth/twitter/redirect')->assertNotFound();
    $this->get('/auth/twitter/callback')->assertNotFound();
});

it('redirects home with a friendly error when the provider is not configured', function () {
    config(['services.google.client_id' => null]);

    $this->get('/auth/google/redirect')
        ->assertRedirect(route('home'))
        ->assertSessionHas('error');
});

it('redirects to home with an error when the provider returns no email', function () {
    mockSocialite(fakeOAuthUser(email: null));

    $this->get('/auth/google/callback')->assertRedirect(route('home'));

    $this->assertGuest();
});
