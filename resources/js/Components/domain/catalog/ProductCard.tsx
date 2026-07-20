import { ProductSummary } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { humanizeSlug } from '@/Utils/text';
import { Link } from '@inertiajs/react';

/** 5-star rating row with average and count — hidden when no rating exists. */
export function RatingStars({
    average,
    count,
    size = 'sm',
}: {
    average: number | null | undefined;
    count?: number;
    size?: 'sm' | 'md';
}) {
    if (average === null || average === undefined) return null;

    const starClasses = size === 'md' ? 'h-4 w-4' : 'h-3.5 w-3.5';
    const filled = Math.round(average);

    return (
        <span className="flex items-center gap-1">
            <span className="flex items-center" aria-label={`Rated ${average} out of 5`}>
                {[1, 2, 3, 4, 5].map((star) => (
                    <svg
                        key={star}
                        viewBox="0 0 20 20"
                        className={`${starClasses} ${star <= filled ? 'text-amber-400' : 'text-gray-200'}`}
                        fill="currentColor"
                    >
                        <path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.28 3.95a1 1 0 0 0 .95.69h4.15c.97 0 1.37 1.24.59 1.81l-3.36 2.44a1 1 0 0 0-.36 1.12l1.28 3.95c.3.92-.75 1.69-1.54 1.12l-3.35-2.44a1 1 0 0 0-1.18 0l-3.35 2.44c-.79.57-1.84-.2-1.54-1.12l1.28-3.95a1 1 0 0 0-.36-1.12L2.08 9.38c-.78-.57-.38-1.81.59-1.81h4.15a1 1 0 0 0 .95-.69l1.28-3.95Z" />
                    </svg>
                ))}
            </span>
            <span className={`${size === 'md' ? 'text-sm' : 'text-xs'} font-medium text-gray-500`}>
                {average.toFixed(1)}
                {count !== undefined && count > 0 && <span className="text-gray-400"> ({count})</span>}
            </span>
        </span>
    );
}

export function CartGlyph({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M2.25 3h1.39c.51 0 .96.34 1.09.83l.38 1.42m0 0L6.98 12.3a1.12 1.12 0 0 0 1.09.84h9.6c.5 0 .94-.33 1.08-.81l1.94-6.34a1.13 1.13 0 0 0-1.08-1.45H5.11ZM8.63 19.13a.94.94 0 1 1-1.88 0 .94.94 0 0 1 1.88 0Zm9.75 0a.94.94 0 1 1-1.87 0 .94.94 0 0 1 1.87 0Z"
            />
        </svg>
    );
}

/**
 * Storefront product card (Temu/AliExpress anatomy): image with hover zoom
 * and Quick look, name, rating stars, app price with struck-through market
 * price, stock-left line, and a cart button that opens the quick view.
 */
export function ProductCard({
    product,
    onQuickView,
    badge,
}: {
    product: ProductSummary;
    /** When provided, powers the Quick look overlay and the cart button. */
    onQuickView?: (product: ProductSummary) => void;
    /** Optional corner badge label, e.g. "DEAL". */
    badge?: string;
}) {
    const hasDiscount =
        product.compareAtPriceKobo !== null &&
        product.compareAtPriceKobo !== undefined &&
        product.compareAtPriceKobo > product.priceKobo;
    const lowStock = product.stockQuantity !== undefined && product.stockQuantity <= 5;

    return (
        <Link
            href={route('catalog.product', { product: product.slug })}
            className="group flex flex-col overflow-hidden rounded-xl border border-gray-100 bg-white transition-shadow hover:shadow-lg"
        >
            <div className="relative aspect-square overflow-hidden bg-gray-50">
                {product.imageUrl ? (
                    <img
                        src={product.imageUrl}
                        alt={product.name}
                        loading="lazy"
                        className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                    />
                ) : (
                    <span className="flex h-full w-full items-center justify-center">
                        <img src="/images/brand/logo-mark-blue.png" alt="" className="h-16 w-16 opacity-30" />
                    </span>
                )}

                {/* Hover tooltip: full name + short description (name cards
                    truncate both, so the tooltip shows the whole story) */}
                <span
                    role="tooltip"
                    className="pointer-events-none absolute inset-x-2 top-2 z-10 translate-y-1 rounded-xl bg-slate-900/90 p-3 opacity-0 shadow-lg backdrop-blur-sm transition-all duration-200 delay-200 group-hover:translate-y-0 group-hover:opacity-100"
                >
                    <span className="block text-xs font-semibold leading-snug text-white">{product.name}</span>
                    {product.description && (
                        <span className="mt-1 line-clamp-3 block text-[11px] leading-snug text-slate-300">
                            {product.description}
                        </span>
                    )}
                </span>

                {badge && (
                    <span className="absolute left-2 top-2 rounded-full bg-orange-500 px-2 py-0.5 text-[10px] font-bold uppercase text-white shadow">
                        {badge}
                    </span>
                )}
                {lowStock && (
                    <span className="absolute bottom-2 right-2 rounded-full bg-slate-900/75 px-2.5 py-1 text-[10px] font-semibold text-white backdrop-blur-sm">
                        Only {product.stockQuantity} left
                    </span>
                )}

                {onQuickView && (
                    <button
                        type="button"
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            onQuickView(product);
                        }}
                        className="absolute bottom-2 left-1/2 hidden -translate-x-1/2 items-center gap-1.5 whitespace-nowrap rounded-full bg-slate-900/80 px-3.5 py-1.5 text-xs font-semibold text-white backdrop-blur-sm transition-colors hover:bg-slate-900 group-hover:flex"
                    >
                        <EyeIcon /> Quick look
                    </button>
                )}
            </div>

            <div className="flex flex-1 flex-col p-3">
                <span className="line-clamp-2 text-sm leading-snug text-gray-800 group-hover:text-brand-600">
                    {product.name}
                </span>

                <span className="mt-1.5">
                    <RatingStars average={product.ratingAverage} count={product.ratingCount} />
                </span>

                <span className="mt-auto flex items-end justify-between gap-2 pt-2">
                    <span className="min-w-0">
                        <span className="flex flex-wrap items-baseline gap-x-1.5">
                            <span className="text-lg font-bold leading-tight text-brand-700">
                                {formatNairaFromKobo(product.priceKobo)}
                            </span>
                            {hasDiscount && (
                                <s className="text-xs text-gray-400">
                                    {formatNairaFromKobo(product.compareAtPriceKobo as number)}
                                </s>
                            )}
                        </span>
                        <span className="block text-xs text-gray-400">
                            {product.stockQuantity !== undefined && (
                                <span className={lowStock ? 'font-semibold text-orange-500' : ''}>
                                    {product.stockQuantity} left
                                </span>
                            )}
                            {product.stockQuantity !== undefined && ' · '}
                            in{' '}
                            <span className="font-medium text-gray-500">{humanizeSlug(product.categorySlug)}</span>
                        </span>
                    </span>

                    <button
                        type="button"
                        aria-label={`Add ${product.name} to cart`}
                        onClick={(e) => {
                            // Without a quick-view handler the click falls
                            // through to the card link (product page).
                            if (onQuickView) {
                                e.preventDefault();
                                e.stopPropagation();
                                onQuickView(product);
                            }
                        }}
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-gray-200 text-gray-600 transition-colors hover:border-brand-600 hover:bg-brand-600 hover:text-white"
                    >
                        <CartGlyph />
                    </button>
                </span>
            </div>
        </Link>
    );
}

function EyeIcon() {
    return (
        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M2.04 12.32a1.01 1.01 0 0 1 0-.64 10.06 10.06 0 0 1 19.92 0 1.01 1.01 0 0 1 0 .64 10.06 10.06 0 0 1-19.92 0Z"
            />
            <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
    );
}
