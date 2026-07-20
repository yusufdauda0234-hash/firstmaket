<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\InitializeDepositAction;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts a wallet top-up (Sprint 4). Validates the amount, initializes a
 * Paystack charge, and hands the browser off to the hosted checkout. The
 * wallet is credited later, only by the verified webhook.
 */
class DepositController extends Controller
{
    public function __construct(private readonly InitializeDepositAction $initialize) {}

    /** Minimum funding amount in kobo (₦100). */
    private const MIN_KOBO = 10000;

    /** Maximum single top-up in kobo (₦5,000,000) — a sanity guardrail. */
    private const MAX_KOBO = 500000000;

    public function create(): InertiaResponse
    {
        return Inertia::render('Wallet/AddMoney');
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'amount_naira' => ['required', 'numeric', 'min:100', 'max:5000000'],
        ]);

        $user = $request->user();

        // A verified phone is mandatory before any money movement, regardless
        // of signup method (docs/firstmarket_Implementation_Plan.md Sprint 2
        // Addendum / Security & Compliance).
        if (! $user->hasVerifiedPhone()) {
            throw ValidationException::withMessages([
                'amount_naira' => 'Please verify your phone number before funding your wallet.',
            ]);
        }

        $amountKobo = (int) round(((float) $validated['amount_naira']) * 100);
        $amountKobo = max(self::MIN_KOBO, min(self::MAX_KOBO, $amountKobo));

        $init = $this->initialize->execute($user, $amountKobo, route('payment.callback'));

        // Full-page redirect to Paystack's hosted checkout.
        return Inertia::location($init->authorizationUrl);
    }
}
