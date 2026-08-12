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
    categoryName?: string;
    priceAlertPercent?: number | null;
    /** ISO timestamp when the live campaign pricing this product is present. Home-page campaign section only. */
    campaignEndsAt?: string | null;
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
    /** Days a customer has to report a problem; null on the staff portals. */
    returnWindowDays?: number | null;
    /** Live-chat widget config; null on the staff and vendor portals. */
    supportChat?: {
        provider: string;
        propertyId: string;
        widgetId: string;
        forGuests: boolean;
    } | null;
    /**
     * Product uuids this customer has saved. Empty for guests and on the
     * staff/vendor portals — see HandleInertiaRequests.
     */
    wishlistUuids?: string[];
    /** Absolute URL of the main marketplace, for links from portal subdomains. */
    mainSiteUrl: string;
    /** Header categories, shared so any customer page can use PublicLayout. */
    categories: Category[];
    /** Total units in the cart — guests included. Drives the header badge. */
    cartCount: number;
    /** Which origin served this page. Drives chrome on cross-portal pages. */
    portal: 'admin' | 'vendor' | 'customer';
    /** Header bell badge. Zero for guests. */
    unreadNotifications: number;
    /** Header support badge — tickets of this customer still open or awaiting them. */
    openTickets: number;
    /** Lowest order value earning free delivery; 0 when none is offered. */
    freeDeliveryFromKobo: number;
    /**
     * Published pages the admin has ticked for the footer (terms, privacy,
     * and anything else). Empty on the admin and vendor subdomains.
     */
    legalLinks: { title: string; url: string }[];
    /**
     * Language + display currency for the storefront. Null on the admin and
     * vendor subdomains, which stay in English naira — see useI18n().
     */
    i18n: import('@/Hooks/useI18n').I18nProps | null;
    [key: string]: unknown;
}
