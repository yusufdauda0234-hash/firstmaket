<?php

namespace App\Shared;

/**
 * Nigerian delivery geography. States are a fixed list rather than a free
 * text box because couriers route on them — a typo like "Legos" is a failed
 * delivery, and a dropdown also lets the checkout validate the address
 * before anyone is charged.
 */
final class Nigeria
{
    /** The 36 states plus the Federal Capital Territory, A–Z. */
    public const STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno',
        'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT - Abuja', 'Gombe',
        'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos',
        'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto',
        'Taraba', 'Yobe', 'Zamfara',
    ];

    public static function isState(string $value): bool
    {
        return in_array($value, self::STATES, true);
    }
}
