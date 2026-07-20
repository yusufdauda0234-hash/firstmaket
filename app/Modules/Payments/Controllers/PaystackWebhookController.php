<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\ProcessPaystackWebhook;
use App\Shared\Contracts\PaymentGatewayContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paystack webhook endpoint (Sprint 4) — the ONLY thing that credits a
 * wallet. Public (no auth, no CSRF), but every request is signature-verified
 * against the raw body before any processing. Invalid signatures are rejected
 * with 400 and never touch a wallet; valid ones are processed idempotently.
 */
class PaystackWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly ProcessPaystackWebhook $processor,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');

        $valid = $this->gateway->verifySignature($rawPayload, $signature);

        if (! $valid) {
            // Log the rejected attempt for audit, then refuse. Returning 400
            // (not 200) means a genuinely misconfigured secret is visible in
            // Paystack's dashboard rather than silently swallowed.
            $this->processor->handle($this->decode($rawPayload), signatureValid: false);

            return response()->json(['status' => 'invalid signature'], 400);
        }

        $this->processor->handle($this->decode($rawPayload), signatureValid: true);

        // Always 200 on a valid, processed (or idempotently skipped) event so
        // Paystack stops retrying.
        return response()->json(['status' => 'ok']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $rawPayload): array
    {
        $decoded = json_decode($rawPayload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
