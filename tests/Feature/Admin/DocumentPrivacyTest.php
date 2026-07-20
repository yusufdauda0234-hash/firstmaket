<?php

use App\Models\UploadedDocument;
use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\DocumentType;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function cacDocument(): UploadedDocument
{
    $vendorUser = User::factory()->create(['user_type' => UserType::Vendor]);
    $vendorUser->assignRole('Vendor');

    $profile = VendorProfile::query()->create([
        'user_id' => $vendorUser->id,
        'business_name' => 'Ada Electronics Ltd',
        'contact_name' => 'Ada Lovelace',
        'status' => VendorStatus::Pending,
    ]);

    Storage::disk('local')->put('cac-documents/test.pdf', 'fake-cac-content');

    return UploadedDocument::query()->create([
        'owner_id' => $profile->id,
        'owner_type' => $profile->getMorphClass(),
        'document_type' => DocumentType::Cac,
        'disk' => 'local',
        'path' => 'cac-documents/test.pdf',
        'original_name' => 'cac.pdf',
        'mime_type' => 'application/pdf',
        'size' => 16,
        'uploaded_by' => $vendorUser->id,
    ]);
}

function documentUrl(UploadedDocument $document): string
{
    return 'http://'.config('app.admin_domain').'/documents/'.$document->uuid;
}

it('redirects guests away from document downloads', function () {
    $document = cacDocument();

    $this->get(documentUrl($document))->assertRedirect();
});

it('denies document downloads to staff without vendors.view', function () {
    $document = cacDocument();

    $logistics = User::factory()->create(['user_type' => UserType::Staff]);
    $logistics->assignRole('Logistics Personnel');
    $logistics->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($logistics)->get(documentUrl($document))->assertForbidden();
});

it('logs a customer out of the admin portal instead of serving documents', function () {
    $document = cacDocument();

    $customer = User::factory()->create();
    $customer->assignRole('Customer');

    $this->actingAs($customer)->get(documentUrl($document))->assertRedirect(route('admin.login'));
    $this->assertGuest();
});

it('streams the document to an Administrator', function () {
    $document = cacDocument();

    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();

    $response = $this->actingAs($admin)->get(documentUrl($document));

    $response->assertOk();
    $response->assertDownload('cac.pdf');
});

it('never serves documents from the customer-facing domain', function () {
    $document = cacDocument();

    // The download route only exists on the admin subdomain.
    $this->get('/documents/'.$document->uuid)->assertNotFound();
});
