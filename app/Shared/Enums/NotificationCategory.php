<?php

namespace App\Shared\Enums;

/**
 * Notification preference categories (docs/FirstMaket-Database_Schema.md
 * section 10). Every outbound notification declares one; the dispatcher
 * checks the user's per-category channel toggles before sending. Security
 * notifications ignore the email toggle — account-safety mail always goes
 * out.
 */
enum NotificationCategory: string
{
    case Orders = 'orders';
    case Savings = 'savings';
    case Security = 'security';
    case Support = 'support';
    case Promotions = 'promotions';

    public function label(): string
    {
        return match ($this) {
            self::Orders => 'Orders and delivery',
            self::Savings => 'Wallet and savings',
            self::Security => 'Account security',
            self::Support => 'Support replies',
            self::Promotions => 'Deals and promotions',
        };
    }

    /** Security email can never be switched off. */
    public function emailLocked(): bool
    {
        return $this === self::Security;
    }
}
