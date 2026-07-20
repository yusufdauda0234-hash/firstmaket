const naira = new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 0,
});

export function formatNairaFromKobo(kobo: number): string {
    return naira.format(kobo / 100);
}
