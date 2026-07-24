<?php

namespace App\Shared\Enums;

/**
 * Ledger entry types (docs/FirstMaket-Database_Schema.md section 7).
 * `deposit` is the only external credit (Sprint 4 Paystack webhook);
 * `plan_contribution`, `open_savings_allocation`, and `cart_checkout` are
 * internal debits that move wallet money into plans / Open Savings / a
 * cart's pay-in-full checkout (Sprint 8). There is deliberately no
 * `withdrawal` type.
 */
enum WalletTransactionType: string
{
    case Deposit = 'deposit';
    case PlanContribution = 'plan_contribution';
    case OpenSavingsAllocation = 'open_savings_allocation';
    case CartCheckout = 'cart_checkout';
    case Redirection = 'redirection';
    case RefundToPlanOnly = 'refund_to_plan_only';
    case Adjustment = 'adjustment';
}
