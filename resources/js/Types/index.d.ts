export interface AuthenticatedUser {
    uuid: string;
    name: string;
    email: string;
    phone: string;
    phoneVerified: boolean;
    roles: string[];
    permissions: string[];
}

export interface Category {
    name: string;
    slug: string;
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
    [key: string]: unknown;
}
