<?php

namespace App\Shared\Enums;

/**
 * Where a plan contribution's money came from
 * (docs/firstmarket-Database_Schema.md section 8, plan_contributions.source).
 * `paystack_deposit` means straight from the wallet balance (which is only
 * ever funded by verified Paystack deposits); `open_savings` is a partial
 * allocation from the Open Savings pot; `redirection` is a full-balance move
 * recorded in plan_redirections.
 */
enum ContributionSource: string
{
    case PaystackDeposit = 'paystack_deposit';
    case OpenSavings = 'open_savings';
    case Redirection = 'redirection';
}
