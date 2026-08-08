<?php

use App\Modules\Identity\Controllers\PhoneVerificationController;
use Illuminate\Support\Facades\Route;

// Self-service phone verification for the VerifyPhoneModal on the vendor
// dashboard — served only on the Vendor Center subdomain (required from
// routes/vendors.php inside its auth middleware group), because that is the
// only origin the modal is ever rendered on. Registering them domain-less
// alongside the other module routes would put them on the main site, where
// NotOnAdminDomain 404s them for the vendor origin and the controller's
// back() would bounce across portals. Customers verify their phone through
// the separate account.identifier.* flow on the main site instead.

Route::post('phone/verify', [PhoneVerificationController::class, 'verify'])->name('phone.verify');
Route::post('phone/send', [PhoneVerificationController::class, 'send'])
    ->middleware('throttle:6,1')
    ->name('phone.send');
