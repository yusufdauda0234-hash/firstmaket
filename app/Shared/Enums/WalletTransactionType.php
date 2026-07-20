<?php

namespace App\Shared\Enums;

/**
 * Ledger entry types (docs/firstmarket-Database_Schema.md section 7).
 * `deposit` is the only external credit (Sprint 4 Paystack webhook);
 * `plan_contribution` and `open_savings_allocation` are the Sprint 5 internal
 * debits that move wallet money into plans / Open Savings. There is
 * deliberately no `withdrawal` type.
 */
enum WalletTransactionType: string
{
    case Deposit = 'deposit';
    case PlanContribution = 'plan_contribution';
    case OpenSavingsAllocation = 'open_savings_allocation';
    case Redirection = 'redirection';
    case RefundToPlanOnly = 'refund_to_plan_only';
    case Adjustment = 'adjustment';
}
