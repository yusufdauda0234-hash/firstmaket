const naira = new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 0,
});

/** Plain grouped digits — no currency symbol. "1000" -> "1,000". */
const grouped = new Intl.NumberFormat('en-NG', { maximumFractionDigits: 0 });

/** Grouped digits keeping up to two decimals, for rates and part-naira values. */
const groupedDecimal = new Intl.NumberFormat('en-NG', { maximumFractionDigits: 2 });

/** ₦ amount from integer kobo. The storage format everywhere in the app. */
export function formatNairaFromKobo(kobo: number): string {
    return naira.format(kobo / 100);
}

/** ₦ amount from whole naira, for figures never stored as kobo. */
export function formatNaira(amount: number): string {
    return naira.format(amount);
}

/**
 * Thousand separators without a currency symbol — for inputs, quantities and
 * anywhere the ₦ is already in the label.
 */
export function formatNumber(value: number, allowDecimals = false): string {
    return (allowDecimals ? groupedDecimal : grouped).format(value);
}

/**
 * Read a number back out of what someone typed.
 *
 * Strips separators and stray currency symbols so "₦1,250" and "1250" mean the
 * same thing — people paste amounts from receipts and messages, and rejecting
 * a comma they did not know was forbidden is a pointless obstacle.
 *
 * Returns null for anything with no digits, so an empty field stays empty
 * rather than silently becoming zero.
 */
export function parseNumber(value: string): number | null {
    const cleaned = value.replace(/[^\d.-]/g, '');

    if (cleaned === '' || cleaned === '-' || cleaned === '.') {
        return null;
    }

    const parsed = Number(cleaned);

    return Number.isFinite(parsed) ? parsed : null;
}
