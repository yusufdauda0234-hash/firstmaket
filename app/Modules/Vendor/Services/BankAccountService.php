<?php

namespace App\Modules\Vendor\Services;

use App\Models\User;
use App\Modules\Vendor\Models\VendorBankAccount;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Contracts\BankAccountResolverContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vendor payout bank account capture with verification
 * (docs/FirstMaket_Implementation_Plan.md Sprint 6): the account name is
 * resolved through the provider before the account is stored, a transfer
 * recipient is registered, and only then is the account marked verified.
 * One active account per vendor; payouts only ever go to verified accounts.
 */
class BankAccountService
{
    public function __construct(
        private readonly BankAccountResolverContract $resolver,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    public function setAccount(User $vendorUser, VendorProfile $vendor, string $bankCode, string $bankName, string $accountNumber): VendorBankAccount
    {
        $accountName = $this->resolver->resolveAccountName($accountNumber, $bankCode);

        if ($accountName === null) {
            throw ValidationException::withMessages([
                'account_number' => 'We could not verify this account. Check the number and bank, then try again.',
            ]);
        }

        $recipientCode = $this->resolver->createTransferRecipient($accountName, $accountNumber, $bankCode);

        return DB::transaction(function () use ($vendorUser, $vendor, $bankCode, $bankName, $accountNumber, $accountName, $recipientCode) {
            // One active account per vendor: deactivate any previous one.
            VendorBankAccount::query()
                ->where('vendor_id', $vendor->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $account = VendorBankAccount::query()->create([
                'vendor_id' => $vendor->id,
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'account_name' => $accountName,
                'paystack_recipient_code' => $recipientCode,
                'verified_at' => now(),
                'is_active' => true,
            ]);

            $this->auditLogger->log(
                actor: $vendorUser,
                subject: $account,
                action: 'vendor.bank_account_set',
                newValues: ['bank_code' => $bankCode, 'account_name' => $accountName],
            );

            return $account;
        });
    }
}
