<?php

namespace App\Modules\Vendor\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Models\VendorBankAccount;
use App\Modules\Vendor\Models\VendorEarning;
use App\Modules\Vendor\Models\VendorPayoutItem;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Services\BankAccountService;
use App\Modules\Vendor\Services\EarningsService;
use App\Shared\Enums\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor Center earnings page (docs/firstmarket_Implementation_Plan.md
 * Sprint 6): cleared balance, pending (in-delivery) earnings, per-order
 * commission breakdown, payout history, and the verified bank account.
 */
class EarningsController extends Controller
{
    public function show(Request $request, EarningsService $earningsService): Response
    {
        $vendor = VendorProfile::query()->where('user_id', $request->user()->id)->firstOrFail();

        // Pending = sold but not yet delivery-confirmed (still in the chain).
        $pendingKobo = (int) Order::query()
            ->where('vendor_id', $vendor->id)
            ->whereNull('earnings_credited_at')
            ->whereNotIn('status', [OrderStatus::VendorRejected, OrderStatus::Cancelled])
            ->sum('vendor_earning_amount_kobo');

        $ledger = VendorEarning::query()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (VendorEarning $earning) => [
                'uuid' => $earning->uuid,
                'type' => $earning->type->value,
                'amountKobo' => $earning->amount_kobo,
                'balanceAfterKobo' => $earning->balance_after_kobo,
                'note' => $earning->note,
                'at' => $earning->created_at?->format('j M Y'),
            ]);

        $payouts = VendorPayoutItem::query()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (VendorPayoutItem $item) => [
                'id' => $item->id,
                'amountKobo' => $item->amount_kobo,
                'status' => $item->status->value,
                'reference' => $item->paystack_transfer_reference,
                'failureReason' => $item->failure_reason,
                'paidAt' => $item->paid_at?->format('j M Y'),
                'createdAt' => $item->created_at->format('j M Y'),
            ]);

        $account = VendorBankAccount::query()
            ->where('vendor_id', $vendor->id)
            ->where('is_active', true)
            ->first();

        return Inertia::render('Vendor/Earnings', [
            'clearedBalanceKobo' => $earningsService->balanceKobo($vendor),
            'pendingKobo' => $pendingKobo,
            'ledger' => $ledger,
            'payouts' => $payouts,
            'bankAccount' => $account === null ? null : [
                'bankName' => $account->bank_name,
                'bankCode' => $account->bank_code,
                'accountName' => $account->account_name,
                // Masked — the full number stays encrypted at rest.
                'accountNumberMasked' => '••••'.substr($account->account_number, -4),
                'verified' => $account->verified_at !== null,
            ],
        ]);
    }

    public function setBankAccount(Request $request, BankAccountService $bankAccountService): RedirectResponse
    {
        $vendor = VendorProfile::query()->where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'digits:10'],
        ]);

        $account = $bankAccountService->setAccount(
            vendorUser: $request->user(),
            vendor: $vendor,
            bankCode: $validated['bank_code'],
            bankName: $validated['bank_name'],
            accountNumber: $validated['account_number'],
        );

        return back()->with('success', "Bank account verified: {$account->account_name}.");
    }
}
