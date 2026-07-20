<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Contracts\AuditLoggerContract;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

/**
 * Mandatory 2FA enrollment for Super Administrator, Administrator, and
 * Finance Officer accounts (docs/firstmarket_Security_Compliance.md
 * section 3). Gated in front of every other admin route by
 * App\Shared\Middleware\EnsureTwoFactorEnrolled.
 */
class TwoFactorController extends Controller
{
    public function setup(Request $request, Google2FA $google2fa): Response
    {
        $user = $request->user();

        if (! $user->two_factor_secret) {
            $user->forceFill([
                'two_factor_secret' => Crypt::encryptString($google2fa->generateSecretKey()),
            ])->save();
        }

        $secret = Crypt::decryptString($user->two_factor_secret);
        $otpAuthUrl = $google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);

        return Inertia::render('Admin/Auth/TwoFactorSetup', [
            'otpAuthUrl' => $otpAuthUrl,
            'secret' => $secret,
            'qrCodeSvg' => $this->qrCodeSvg($otpAuthUrl),
        ]);
    }

    /**
     * Renders the otpauth:// URI as an inline SVG so the page never leaks
     * the 2FA secret to a third-party QR image service.
     */
    private function qrCodeSvg(string $otpAuthUrl): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        // Drop the XML declaration; the SVG is inlined into the page.
        return trim(preg_replace('/^<\?xml.*?\?>/', '', $writer->writeString($otpAuthUrl)));
    }

    public function confirm(Request $request, Google2FA $google2fa, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();
        $secret = Crypt::decryptString($user->two_factor_secret);

        if (! $google2fa->verifyKey($secret, $request->string('code')->value())) {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Try again.',
            ]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $auditLogger->log(actor: $user, subject: $user, action: 'auth.two_factor_enrolled');

        return redirect()->route('admin.dashboard');
    }
}
