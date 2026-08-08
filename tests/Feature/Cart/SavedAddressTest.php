<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * A delivery address is entered once and remembered. Retyping the street,
 * LGA and recipient for every order is the fastest way to lose a checkout.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 50_000_00]);
});

/** @return array<string, string> */
function anAddress(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Yakubu Dauda',
        'recipient_phone' => '08031234567',
        'delivery_address' => '12 Marina Road',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'landmark' => 'Opposite the filling station',
    ], $overrides);
}

it('saves the address without placing an order', function () {
    $this->actingAs($this->customer)
        ->post(route('cart.checkout.address'), anAddress())
        ->assertRedirect()
        ->assertSessionHas('success');

    $profile = $this->customer->customerProfile->refresh();

    // Stored SHOUTING, per the Uppercase cast…
    expect($profile->getRawOriginal('default_address'))->toBe('12 MARINA ROAD')
        ->and($profile->getRawOriginal('default_lga'))->toBe('ETI-OSA')
        ->and($profile->getRawOriginal('default_recipient_name'))->toBe('YAKUBU DAUDA');

    // …and handed back presentable, which is what a form should prefill with.
    expect($profile->default_address)->toBe('12 Marina Road')
        ->and($profile->default_state)->toBe('Lagos')
        ->and($profile->default_lga)->toBe('Eti-Osa')
        ->and($profile->default_recipient_name)->toBe('Yakubu Dauda')
        ->and($profile->default_recipient_phone)->toBe('08031234567')
        ->and($profile->default_landmark)->toBe('Opposite the Filling Station');
});

it('prefills checkout from the saved address', function () {
    $this->actingAs($this->customer)->post(route('cart.checkout.address'), anAddress());

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    $this->actingAs($this->customer)
        ->get(route('cart.checkout'))
        ->assertInertia(fn ($page) => $page
            ->where('savedAddress.delivery_address', '12 Marina Road')
            ->where('savedAddress.state', 'Lagos')
            ->where('savedAddress.lga', 'Eti-Osa')
            ->where('savedAddress.recipient_name', 'Yakubu Dauda'));
});

it('sends null when nothing has been saved', function () {
    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    $this->actingAs($this->customer)
        ->get(route('cart.checkout'))
        ->assertInertia(fn ($page) => $page->where('savedAddress', null));
});

it('does not prefill from half an address', function () {
    // State and LGA alone are not enough for a courier.
    $this->customer->customerProfile->update(['default_state' => 'Kano', 'default_lga' => 'Nassarawa']);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    $this->actingAs($this->customer)
        ->get(route('cart.checkout'))
        ->assertInertia(fn ($page) => $page->where('savedAddress', null));
});

it('replaces the saved address when a new one is entered', function () {
    $this->actingAs($this->customer)->post(route('cart.checkout.address'), anAddress());

    $this->actingAs($this->customer)->post(route('cart.checkout.address'), anAddress([
        'delivery_address' => '5 Ahmadu Bello Way',
        'state' => 'Kano',
        'lga' => 'Nassarawa',
    ]));

    $profile = $this->customer->customerProfile->refresh();

    expect($profile->default_address)->toBe('5 Ahmadu Bello Way')
        ->and($profile->default_state)->toBe('Kano')
        ->and($profile->default_lga)->toBe('Nassarawa');
});

it('rejects an address a courier cannot use', function () {
    $this->actingAs($this->customer)
        ->post(route('cart.checkout.address'), anAddress([
            'recipient_phone' => '12345',
            'state' => 'Atlantis',
        ]))
        ->assertSessionHasErrors(['recipient_phone', 'state']);

    expect($this->customer->customerProfile->refresh()->default_address)->toBeNull();
});

it('creates the profile when the customer has none', function () {
    $fresh = User::factory()->create(['phone_verified_at' => now()]);
    $fresh->assignRole('Customer');

    expect($fresh->customerProfile)->toBeNull();

    $this->actingAs($fresh)->post(route('cart.checkout.address'), anAddress())->assertRedirect();

    expect($fresh->refresh()->customerProfile->default_address)->toBe('12 Marina Road');
});

it('will not save an address for a guest', function () {
    $this->post(route('cart.checkout.address'), anAddress())->assertRedirect(route('login'));
});
