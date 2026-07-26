<?php

use App\Models\UploadedDocument;
use App\Models\User;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function vendorPayload(array $overrides = []): array
{
    return array_merge([
        'business_name' => 'Ada Electronics Ltd',
        'contact_name' => 'Ada Lovelace',
        'email' => 'vendor@example.com',
        'phone' => '+2348012345678',
        'address' => '12 Allen Avenue, Ikeja, Lagos',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'cac_document' => UploadedFile::fake()->create('cac.pdf', 100, 'application/pdf'),
    ], $overrides);
}

it('registers a vendor with a pending profile', function () {
    $response = $this->post(route('vendor.register'), vendorPayload());

    $user = User::query()->where('email', 'vendor@example.com')->firstOrFail();

    expect($user->hasRole('Vendor'))->toBeTrue()
        ->and($user->user_type)->toBe(UserType::Vendor)
        ->and($user->vendorProfile->status)->toBe(VendorStatus::Pending)
        ->and($user->vendorProfile->business_name)->toBe('Ada Electronics Ltd');

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('vendor.login', ['registered' => 1]));
});

it('stores the CAC document on the private disk', function () {
    $this->post(route('vendor.register'), vendorPayload());

    $document = UploadedDocument::query()->firstOrFail();

    expect($document->disk)->toBe('local')
        ->and($document->document_type->value)->toBe('cac')
        ->and($document->original_name)->toBe('cac.pdf');

    Storage::disk('local')->assertExists($document->path);
});

it('does not expose the CAC document at a public URL', function () {
    $this->post(route('vendor.register'), vendorPayload());

    $document = UploadedDocument::query()->firstOrFail();

    // The private local disk's serve route refuses unsigned requests, so a
    // guessed /storage/... URL never returns the file.
    $response = $this->get('/storage/'.$document->path);

    expect($response->status())->toBeIn([403, 404]);
});

it('requires a CAC document', function () {
    $response = $this->post(route('vendor.register'), vendorPayload(['cac_document' => null]));

    $response->assertSessionHasErrors('cac_document');
    expect(User::query()->where('email', 'vendor@example.com')->exists())->toBeFalse();
});

it('rejects executable file types for the CAC document', function () {
    $response = $this->post(route('vendor.register'), vendorPayload([
        'cac_document' => UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload'),
    ]));

    $response->assertSessionHasErrors('cac_document');
});
