<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Sprint 4: starting a deposit initializes a Paystack charge and hands the
 * browser to hosted checkout. No wallet is credited here. Phone verification
 * is not required to fund the wallet — it's a secondary/optional identifier
 * while SMS OTP delivery isn't reliable yet.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config([
        'services.paystack.secret_key' => 'sk_test_init',
        'services.paystack.base_url' => 'https://api.paystack.co',
    ]);
});

it('initializes a Paystack charge and redirects to hosted checkout', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'FMW_generated',
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'acc_123',
            ],
        ]),
    ]);

    $customer = User::factory()->create(['phone_verified_at' => now()]);
    $customer->assignRole('Customer');

    $this->actingAs($customer)
        ->post(route('wallet.deposit'), ['amount_naira' => 5000])
        ->assertRedirect('https://checkout.paystack.com/abc123');

    $this->assertDatabaseHas('paystack_transactions', [
        'user_id' => $customer->id,
        'amount_kobo' => 500000,
        'status' => 'pending',
    ]);

    // Nothing credited yet — that only happens on the webhook.
    $this->assertDatabaseCount('wallet_transactions', 0);
});

it('allows funding without a verified phone', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'FMW_generated',
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'acc_123',
            ],
        ]),
    ]);

    $customer = User::factory()->create(['phone_verified_at' => null]);
    $customer->assignRole('Customer');

    $this->actingAs($customer)
        ->post(route('wallet.deposit'), ['amount_naira' => 5000])
        ->assertRedirect('https://checkout.paystack.com/abc123');

    $this->assertDatabaseHas('paystack_transactions', [
        'user_id' => $customer->id,
        'amount_kobo' => 500000,
        'status' => 'pending',
    ]);
});

it('rejects a below-minimum deposit amount', function () {
    $customer = User::factory()->create(['phone_verified_at' => now()]);
    $customer->assignRole('Customer');

    $this->actingAs($customer)
        ->post(route('wallet.deposit'), ['amount_naira' => 50])
        ->assertSessionHasErrors('amount_naira');
});
