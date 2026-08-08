<?php

namespace App\Shared\Enums;

/**
 * Savings ledger entry types (docs/FirstMaket-Database_Schema.md section 7).
 *
 * `deposit` is the only external credit — the verified Paystack webhook.
 * `refund` puts money back after a vendor rejection, and lands in savings
 * rather than a card, because savings buys products and never pays out cash.
 * The rest are internal debits that spend the balance on goods: a cart paid
 * in full, or a savings goal that has reached its target. There is
 * deliberately no withdrawal type.
 */
enum SavingsTransactionType: string
{
    case Deposit = 'deposit';
    case Refund = 'refund';
    case CartCheckout = 'cart_checkout';
    case GoalFulfilment = 'goal_fulfilment';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Added to savings',
            self::Refund => 'Refund to savings',
            self::CartCheckout => 'Order paid',
            self::GoalFulfilment => 'Goal completed',
            self::Adjustment => 'Adjustment',
        };
    }
}
