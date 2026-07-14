<?php

namespace App\Shared\Security;

use Illuminate\Http\Request;

final class DeviceFingerprint
{
    /**
     * Placeholder heuristic: hashes the User-Agent string only, so login
     * events and new-device alerts are real rather than stubbed for Sprint 1.
     * Replace with a proper client-side fingerprint (e.g. FingerprintJS)
     * before production — User-Agent alone is weak and shared across users
     * on the same browser/OS/version combination.
     */
    public static function fromRequest(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }
}
