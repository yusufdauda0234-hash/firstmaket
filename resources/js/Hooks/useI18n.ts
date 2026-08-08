import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

export interface LocaleOption {
    code: string;
    endonym: string;
    english: string;
    badge: string;
}

export interface CurrencyOption {
    code: string;
    symbol: string;
    name: string;
    /** Units of this currency per ₦1. NGN is 1. */
    rate: number;
    decimals: number;
    isBase: boolean;
}

export interface I18nProps {
    locale: string;
    intlTag: string;
    locales: LocaleOption[];
    translations: Record<string, string>;
    country: string;
    currency: CurrencyOption;
    currencies: CurrencyOption[];
}

/** Naira, used on the staff portals and as the fallback everywhere. */
const NAIRA: CurrencyOption = {
    code: 'NGN',
    symbol: '₦',
    name: 'Nigerian Naira',
    rate: 1,
    decimals: 0,
    isBase: true,
};

/**
 * The shared i18n payload, or a naira/English stand-in.
 *
 * The stand-in is what keeps the admin workspace and Vendor Center working:
 * HandleInertiaRequests deliberately sends null there, so those pages keep
 * reading ledger figures in naira and English labels with no changes.
 */
export function useI18n(): I18nProps {
    const { i18n } = usePage<{ i18n: I18nProps | null }>().props;

    return useMemo(
        () =>
            i18n ?? {
                locale: 'en',
                intlTag: 'en-NG',
                locales: [],
                translations: {},
                country: 'NG',
                currency: NAIRA,
                currencies: [],
            },
        [i18n],
    );
}

export type Translate = (key: string, replacements?: Record<string, string | number>) => string;

/**
 * Look a string up by its English text.
 *
 * Keying on English rather than dot-paths means an untranslated call site
 * still renders correct English instead of "cart.summary.title", so adding a
 * new string never leaves a raw key on the page.
 */
export function useTranslation(): { t: Translate; locale: string } {
    const { translations, locale } = useI18n();

    const t = useCallback<Translate>(
        (key, replacements) => {
            let line = translations[key] ?? key;

            if (replacements) {
                for (const [token, value] of Object.entries(replacements)) {
                    line = line.replaceAll(`:${token}`, String(value));
                }
            }

            return line;
        },
        [translations],
    );

    return { t, locale };
}

/**
 * Formats an integer kobo amount in the shopper's chosen display currency.
 *
 * Prices are stored and charged in naira; this only converts what is shown
 * while browsing. `naira()` stays available for the amount actually charged —
 * the pay button, plan instalments, receipts — so a shopper browsing in
 * dollars still sees the exact naira figure Paystack will take.
 */
export function useMoney() {
    const { currency, intlTag } = useI18n();

    return useMemo(() => {
        const build = (c: CurrencyOption) =>
            new Intl.NumberFormat(intlTag, {
                style: 'currency',
                currency: c.code,
                minimumFractionDigits: c.decimals,
                maximumFractionDigits: c.decimals,
            });

        let display: Intl.NumberFormat;
        try {
            display = build(currency);
        } catch {
            // Intl rejects a code it does not know — fall back rather than
            // throwing inside a render.
            display = build(NAIRA);
        }

        const nairaFormat = build(NAIRA);

        return {
            /** In the shopper's chosen currency. Browse, cart, checkout preview. */
            money: (kobo: number) => display.format((kobo / 100) * currency.rate),
            /** Always naira — the amount that will actually be charged. */
            naira: (kobo: number) => nairaFormat.format(kobo / 100),
            currency,
            /** True when the shopper is looking at converted, indicative prices. */
            isConverted: !currency.isBase,
        };
    }, [currency, intlTag]);
}
