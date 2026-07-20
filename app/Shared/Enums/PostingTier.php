<?php

namespace App\Shared\Enums;

/**
 * Vendor listing fee tiers (docs/firstmarket_PRD_Laravel.md "Vendor fee
 * settings"). Fee amounts and the free/paid switch live in
 * vendor_fee_settings, admin-managed.
 */
enum PostingTier: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Premium = 'premium';
    case Featured = 'featured';
}
