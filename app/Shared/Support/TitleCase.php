<?php

namespace App\Shared\Support;

/**
 * Turns the upper-case text we store into something presentable.
 *
 * Records are written in upper case so they sort and match consistently
 * (see \App\Shared\Casts\Uppercase), but SHOUTING AT THE CUSTOMER reads
 * badly on a product card or an invoice. This converts back to title case
 * without flattening the things that genuinely belong in capitals:
 *
 *   BRIGHT ELECTRONICS LTD        → Bright Electronics Ltd
 *   SAMSUNG 55" QLED SMART TV     → Samsung 55" QLED Smart TV
 *   INFINIX NOTE 40 PRO 256GB     → Infinix Note 40 Pro 256GB
 *   12 MARINA ROAD, ETI-OSA       → 12 Marina Road, Eti-Osa
 *
 * Multibyte-aware throughout, so a non-Latin name is not corrupted.
 */
final class TitleCase
{
    /**
     * Words that stay in capitals. Product names in this catalogue lean
     * heavily on hardware acronyms, and "Tv"/"Usb"/"Ltd" look like typos.
     *
     * @var array<int, string>
     */
    private const ACRONYMS = [
        // Display and hardware
        'TV', 'HD', 'FHD', 'UHD', 'LED', 'OLED', 'QLED', 'LCD', 'IPS', 'HDR',
        'USB', 'HDMI', 'VGA', 'SSD', 'HDD', 'RAM', 'ROM', 'CPU', 'GPU', 'PC',
        'AC', 'DC', 'RGB', 'ANC', 'TWS', 'IP', 'NFC', 'GPS', 'LTE', 'GSM',
        'SIM', 'OTG', 'PD', 'QC', 'BT', 'IR', 'UV', 'LPG',
        // Business and Nigerian usage. Ltd and Nig are deliberately absent —
        // they are abbreviations that read as words, not initialisms.
        'PLC', 'NG', 'UK', 'US', 'USA', 'CAC', 'VAT', 'NIN',
        'BVN', 'POS', 'ATM', 'PHCN', 'NEPA', 'MTN', 'DSTV', 'GOTV', 'FCMB',
        'UBA', 'GTB', 'LG', 'HP', 'JBL', 'HMO', 'NGO', 'FCT',
        // Sizing
        'XL', 'XXL', 'XXXL', 'XS',
    ];

    /**
     * Small words that stay lower case inside a title — but never as the
     * first word, where they still get capitalised.
     *
     * @var array<int, string>
     */
    private const MINOR = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'nor', 'of',
        'on', 'or', 'the', 'to', 'via', 'with',
    ];

    /** Whitespace runs, kept so the original spacing survives. */
    private const SPACES = '/(\s+)/u';

    /** Punctuation inside a word that starts a new one: ETI-OSA, A/C, MR.X */
    private const INNER = '/([\-\/\.,])/u';

    public static function format(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim($value);

        if ($text === '') {
            return null;
        }

        // Mixed case already means a human chose it — leave it alone. Only
        // text that is entirely upper case is ours to reformat.
        if ($text !== mb_strtoupper($text, 'UTF-8')) {
            return $text;
        }

        $tokens = preg_split(self::SPACES, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($tokens === false) {
            return $text;
        }

        $firstWordSeen = false;

        foreach ($tokens as $index => $token) {
            if ($token === '' || preg_match(self::SPACES, $token) === 1) {
                continue;
            }

            // A token carrying a digit is a model number, capacity or size —
            // WH-CH720N, 256GB, 20000MAH, 55". Splitting or recasing those
            // only breaks them, so the whole token passes through untouched.
            if (preg_match('/\d/u', $token) === 1) {
                $firstWordSeen = true;

                continue;
            }

            $tokens[$index] = self::token($token, isFirst: ! $firstWordSeen);
            $firstWordSeen = true;
        }

        return implode('', $tokens);
    }

    /** One whitespace-delimited token, which may still hold ETI-OSA or A/C. */
    private static function token(string $token, bool $isFirst): string
    {
        $parts = preg_split(self::INNER, $token, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return self::word($token, $isFirst);
        }

        $first = $isFirst;

        foreach ($parts as $index => $part) {
            if ($part === '' || preg_match(self::INNER, $part) === 1) {
                continue;
            }

            $parts[$index] = self::word($part, $first);
            $first = false;
        }

        return implode('', $parts);
    }

    private static function word(string $word, bool $isFirst): string
    {
        $bare = preg_replace('/[^\p{L}]/u', '', $word) ?? $word;

        if ($bare !== '' && in_array(mb_strtoupper($bare, 'UTF-8'), self::ACRONYMS, true)) {
            return $word;
        }

        // A single letter is an initial ("YAKUBU D. MUSA") — keep it capital.
        if (mb_strlen($bare, 'UTF-8') === 1) {
            return $word;
        }

        $lower = mb_strtolower($word, 'UTF-8');

        if (! $isFirst && in_array($lower, self::MINOR, true)) {
            return $lower;
        }

        return mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8')
            .mb_substr($lower, 1, null, 'UTF-8');
    }
}
