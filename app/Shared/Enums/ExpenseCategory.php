<?php

namespace App\Shared\Enums;

/**
 * What the money was spent on.
 *
 * A fixed list rather than free text: the point of recording an expense is to
 * be able to add it up later, and "Fuel", "fuel" and "Petrol" typed by three
 * different people do not add up to anything.
 */
enum ExpenseCategory: string
{
    case Salaries = 'salaries';
    case Rent = 'rent';
    case Logistics = 'logistics';
    case Marketing = 'marketing';
    case Technology = 'technology';
    case Utilities = 'utilities';
    case BankCharges = 'bank_charges';
    case ProfessionalFees = 'professional_fees';
    case OfficeSupplies = 'office_supplies';
    case Travel = 'travel';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Salaries => 'Salaries and wages',
            self::Rent => 'Rent',
            self::Logistics => 'Logistics and fuel',
            self::Marketing => 'Marketing and advertising',
            self::Technology => 'Software and hosting',
            self::Utilities => 'Utilities',
            self::BankCharges => 'Bank charges',
            self::ProfessionalFees => 'Professional fees',
            self::OfficeSupplies => 'Office supplies',
            self::Travel => 'Travel',
            self::Other => 'Other',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $category) => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
