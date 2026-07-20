<?php

namespace App\Shared\Utils;

/**
 * Kobo formatting for human-facing text (emails, notifications). The
 * frontend has its own formatter in resources/js/Utils/money.ts.
 */
final class Money
{
    public static function formatKobo(int $amountKobo): string
    {
        $naira = intdiv($amountKobo, 100);
        $kobo = $amountKobo % 100;

        return '₦'.number_format($naira).($kobo > 0 ? '.'.str_pad((string) $kobo, 2, '0', STR_PAD_LEFT) : '');
    }
}
