export interface AuthenticatedUser {
    uuid: string;
    name: string;
    email: string;
    phone: string;
    phoneVerified: boolean;
    roles: string[];
    permissions: string[];
    /** Null unless this account sells. Drives what the Vendor Center offers. */
    vendorStatus?: 'pending' | 'approved' | 'rejected' | 'suspended' | 'banned' | null;
}

export interface Category {
    name: string;
    slug: string;
    /** Sub-categories, so the header menu can drill into a parent. */
    children?: { name: string; slug: string }[];
}

/** Shape the public product sections will receive once Sprint 3 lands. */
export interface ProductSummary {
    uuid: string;
    name: string;
    slug: string;
    priceKobo: number;
    /** Vendor-claimed usual market price — shown struck through when higher. */
    compareAtPriceKobo?: number | null;
    ratingAverage?: number | null;
    ratingCount?: number;
    imageUrl: string | null;
    /** All gallery image URLs, primary first — home payload only. */
    imageUrls?: string[];
    categorySlug: string;
    /** Present on home-page payloads (quick view); absent elsewhere. */
    description?: string;
    stockQuantity?: number;
    vendorName?: string;
}

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    /** 1-based index of the first row on this page; null when empty. Drives S/N. */
    from: number | null;
    to: number | null;
    current_page: number;
    per_page: number;
}

export interface PageProps {
    auth: {
        user: AuthenticatedUser | null;
    };
    flash: {
        success?: string;
        error?: string;
        /** Local/debug builds only — see PhoneVerificationController. */
        devOtpCode?: string | null;
    };
    supportHotline: string;
    /** Absolute URL of the main marketplace, for links from portal subdomains. */
    mainSiteUrl: string;
    /** Header categories, shared so any customer page can use PublicLayout. */
    categories: Category[];
    /** Total units in the cart — guests included. Drives the header badge. */
    cartCount: number;
    /** Lowest order value earning free delivery; 0 when none is offered. */
    freeDeliveryFromKobo: number;
    /**
     * Language + display currency for the storefront. Null on the admin and
     * vendor subdomains, which stay in English naira — see useI18n().
     */
    i18n: import('@/Hooks/useI18n').I18nProps | null;
    [key: string]: unknown;
}
