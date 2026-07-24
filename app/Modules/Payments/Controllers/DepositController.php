<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\InitializeDepositAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
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

    /**
     * "Add money" is a modal on the wallet page rather than its own route —
     * this just gets any old/external link to that modal open.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('wallet.index', ['add_money' => 1]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'amount_naira' => ['required', 'numeric', 'min:100', 'max:5000000'],
        ]);

        $user = $request->user();

        // Phone verification is not required to fund the wallet for now —
        // SMS OTP delivery isn't reliable yet (SmartSMSSolutions
        // transactional route pending). Phone is a secondary/optional
        // identifier until that's back.
        $amountKobo = (int) round(((float) $validated['amount_naira']) * 100);
        $amountKobo = max(self::MIN_KOBO, min(self::MAX_KOBO, $amountKobo));

        $init = $this->initialize->execute($user, $amountKobo, route('payment.callback'));

        // Full-page redirect to Paystack's hosted checkout.
        return Inertia::location($init->authorizationUrl);
    }
}
