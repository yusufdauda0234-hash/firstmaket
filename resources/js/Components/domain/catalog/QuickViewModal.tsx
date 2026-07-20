import { useAuthModal } from '@/Components/domain/auth/auth-modal-context';
import { CartGlyph, RatingStars } from '@/Components/domain/catalog/ProductCard';
import { PageProps, ProductSummary } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { humanizeSlug } from '@/Utils/text';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * Quick-look preview (AliExpress pattern): three columns — image slider,
 * description, and a buy box (price + quantity + add to cart) — with a
 * "You may also like" carousel band below.
 */
export default function QuickViewModal({
    product,
    pool,
    onSwitch,
    onClose,
}: {
    product: ProductSummary;
    /** Candidate products for the "You may also like" row. */
    pool: ProductSummary[];
    onSwitch: (product: ProductSummary) => void;
    onClose: () => void;
}) {
    const [quantity, setQuantity] = useState(1);
    const [activeImage, setActiveImage] = useState<string | null>(product.imageUrl);
    // Cursor-follow zoom over the main image (AliExpress lens behavior).
    const [zoomOrigin, setZoomOrigin] = useState<{ x: number; y: number } | null>(null);
    const maxQuantity = Math.max(product.stockQuantity ?? 1, 1);
    const similarRef = useRef<HTMLDivElement>(null);
    const openAuth = useAuthModal();
    const { auth } = usePage<PageProps>().props;

    const gallery =
        product.imageUrls && product.imageUrls.length > 0
            ? product.imageUrls
            : product.imageUrl
              ? [product.imageUrl]
              : [];

    const hasDiscount =
        product.compareAtPriceKobo !== null &&
        product.compareAtPriceKobo !== undefined &&
        product.compareAtPriceKobo > product.priceKobo;
    const savingsPercent = hasDiscount
        ? Math.round((1 - product.priceKobo / (product.compareAtPriceKobo as number)) * 100)
        : 0;

    // Reset quantity and active image whenever the previewed product changes.
    useEffect(() => {
        setQuantity(1);
        setActiveImage(product.imageUrl);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [product.uuid]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [onClose]);

    // Same category first, then everything else, never the product itself.
    const similar = [
        ...pool.filter((p) => p.uuid !== product.uuid && p.categorySlug === product.categorySlug),
        ...pool.filter((p) => p.uuid !== product.uuid && p.categorySlug !== product.categorySlug),
    ].slice(0, 12);

    function scrollSimilar(direction: 1 | -1) {
        const el = similarRef.current;
        el?.scrollBy({ left: direction * el.clientWidth * 0.8, behavior: 'smooth' });
    }

    function addToCart() {
        // The cart module is still on the roadmap: guests get the sign-in
        // modal; signed-in shoppers land on the full product page. The quick
        // view stays open underneath so cancelling the login returns here.
        if (!auth.user) {
            openAuth();
            return;
        }
        router.get(route('catalog.product', { product: product.slug }));
    }

    return (
        <div
            className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/70 p-4"
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            role="dialog"
            aria-modal="true"
            aria-label={`Quick look: ${product.name}`}
        >
            <div className="animate-popIn relative flex max-h-[94vh] w-full max-w-7xl flex-col overflow-y-auto rounded-3xl bg-white shadow-2xl">
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close preview"
                    className="absolute right-3 top-3 z-10 rounded-full bg-slate-900/60 p-2 text-white backdrop-blur-sm transition hover:bg-slate-900/80"
                >
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>

                {/* Main: 3 columns — image slider | description | buy box.
                    Keeping price + cart in their own column shortens the
                    modal enough that nothing needs to scroll on desktop. */}
                <div className="grid sm:grid-cols-[0.85fr_1.15fr] lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)_minmax(0,0.85fr)]">
                    {/* Image slider with thumbnail captions */}
                    <div key={product.uuid} className="hidden animate-countUp flex-col p-5 sm:flex">
                        <div
                            className={`flex h-64 w-full items-center justify-center overflow-hidden rounded-2xl bg-gray-50 lg:h-72 ${
                                activeImage ? 'cursor-zoom-in' : ''
                            }`}
                            onMouseMove={(e) => {
                                if (!activeImage) return;
                                const rect = e.currentTarget.getBoundingClientRect();
                                setZoomOrigin({
                                    x: ((e.clientX - rect.left) / rect.width) * 100,
                                    y: ((e.clientY - rect.top) / rect.height) * 100,
                                });
                            }}
                            onMouseLeave={() => setZoomOrigin(null)}
                        >
                            {activeImage ? (
                                <img
                                    src={activeImage}
                                    alt={product.name}
                                    style={
                                        zoomOrigin
                                            ? {
                                                  transformOrigin: `${zoomOrigin.x}% ${zoomOrigin.y}%`,
                                                  transform: 'scale(1.9)',
                                              }
                                            : undefined
                                    }
                                    className="h-full w-full object-cover transition-transform duration-150 ease-out"
                                />
                            ) : (
                                <img src="/images/brand/logo-mark-blue.png" alt="" className="h-20 w-20 opacity-30" />
                            )}
                        </div>

                        {gallery.length > 1 && (
                            <div className="mt-3 flex justify-center gap-2">
                                {gallery.map((url) => {
                                    const active = url === activeImage;
                                    return (
                                        <button
                                            key={url}
                                            type="button"
                                            onClick={() => setActiveImage(url)}
                                            aria-label="View this photo"
                                            aria-pressed={active}
                                            className={`h-14 w-14 shrink-0 overflow-hidden rounded-lg border-2 transition ${
                                                active
                                                    ? 'border-brand-600 ring-2 ring-brand-600/20'
                                                    : 'border-gray-200 opacity-70 hover:opacity-100'
                                            }`}
                                        >
                                            <img src={url} alt="" loading="lazy" className="h-full w-full object-cover" />
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    {/* Description column */}
                    <div className="flex flex-col p-5 sm:pr-12 lg:pr-5">
                        {product.vendorName && (
                            <p className="mb-2 flex items-center justify-between gap-2 border-b border-gray-100 pb-2 text-sm">
                                <span className="text-gray-400">Sold by</span>
                                <span className="truncate font-semibold text-gray-800">{product.vendorName}</span>
                            </p>
                        )}

                        <Link
                            href={route('catalog.index', { category: product.categorySlug })}
                            onClick={onClose}
                            className="w-fit rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-100"
                        >
                            {humanizeSlug(product.categorySlug)}
                        </Link>

                        <h2 className="mt-2 text-lg font-bold leading-snug text-gray-900">{product.name}</h2>

                        <div className="mt-1">
                            <RatingStars average={product.ratingAverage} count={product.ratingCount} size="md" />
                        </div>

                        {product.description && (
                            <p className="mt-2.5 text-sm leading-relaxed text-gray-600">{product.description}</p>
                        )}

                        <ul className="mt-2.5 space-y-1 text-sm text-gray-600">
                            <li className="flex items-center gap-2">
                                <span className="text-green-600">✓</span> Verified vendor
                            </li>
                            <li className="flex items-center gap-2">
                                <span className="text-green-600">✓</span> FirstMarket delivery — vendors never see your details
                            </li>
                            <li className="flex items-center gap-2">
                                <span className="text-green-600">✓</span> Secure Paystack checkout
                            </li>
                        </ul>
                    </div>

                    {/* Buy box: price + quantity + cart in their own column */}
                    <div className="flex flex-col p-5 sm:col-span-2 sm:pt-0 lg:col-span-1 lg:pr-14 lg:pt-5">
                        <div className="rounded-2xl border border-gray-200 p-4 shadow-sm">
                            {/* Price band */}
                            <div className="rounded-xl border border-brand-yellow/50 bg-brand-yellow/10 px-4 py-2.5">
                                <p className="text-[11px] font-bold uppercase tracking-wide text-brand-700">
                                    FirstMarket price
                                </p>
                                <p className="flex flex-wrap items-baseline gap-x-2.5">
                                    <span className="text-2xl font-extrabold tracking-tight text-brand-700">
                                        {formatNairaFromKobo(product.priceKobo)}
                                    </span>
                                    {hasDiscount && (
                                        <>
                                            <s className="text-sm text-gray-400">
                                                {formatNairaFromKobo(product.compareAtPriceKobo as number)}
                                            </s>
                                            <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-600">
                                                -{savingsPercent}%
                                            </span>
                                        </>
                                    )}
                                </p>
                            </div>

                            {/* Quantity row, then a full-width Add to cart —
                                stacked so the button never wraps in the
                                narrow buy-box column */}
                            <div className="pt-4">
                                {product.stockQuantity !== undefined && (
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-sm font-semibold text-gray-900">
                                            Quantity
                                            <span className="ml-2 text-xs font-normal text-gray-400">
                                                {product.stockQuantity} available
                                            </span>
                                        </p>
                                        <div className="flex shrink-0 items-center gap-1 rounded-full border border-gray-200 px-1.5 py-1">
                                            <button
                                                type="button"
                                                onClick={() => setQuantity((q) => Math.max(1, q - 1))}
                                                disabled={quantity <= 1}
                                                aria-label="Decrease quantity"
                                                className="flex h-7 w-7 items-center justify-center rounded-full text-gray-600 transition hover:bg-slate-100 active:scale-90 disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                −
                                            </button>
                                            <input
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                max={maxQuantity}
                                                value={quantity}
                                                aria-label="Quantity"
                                                onChange={(e) => {
                                                    const parsed = parseInt(e.target.value, 10);
                                                    setQuantity(
                                                        Number.isNaN(parsed)
                                                            ? 1
                                                            : Math.min(maxQuantity, Math.max(1, parsed)),
                                                    );
                                                }}
                                                onFocus={(e) => e.target.select()}
                                                className="w-12 border-0 bg-transparent p-0 text-center text-sm font-semibold text-gray-900 [appearance:textfield] focus:outline-none focus:ring-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setQuantity((q) => Math.min(maxQuantity, q + 1))}
                                                disabled={quantity >= maxQuantity}
                                                aria-label="Increase quantity"
                                                className="flex h-7 w-7 items-center justify-center rounded-full text-gray-600 transition hover:bg-slate-100 active:scale-90 disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                +
                                            </button>
                                        </div>
                                    </div>
                                )}
                                <button
                                    type="button"
                                    onClick={addToCart}
                                    className="mt-3 flex w-full items-center justify-center gap-2 whitespace-nowrap rounded-full bg-brand-600 py-3 text-sm font-bold text-white transition hover:bg-brand-700 hover:shadow-lg hover:shadow-brand-600/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-yellow active:scale-[0.98]"
                                >
                                    <CartGlyph /> Add to cart
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* You may also like — carousel band */}
                {similar.length > 0 && (
                    <div className="border-t border-gray-100 bg-slate-50 p-4">
                        <div className="mb-2.5 flex items-center justify-between">
                            <h3 className="text-sm font-bold text-gray-900">You may also like</h3>
                            <div className="flex gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => scrollSimilar(-1)}
                                    aria-label="Previous items"
                                    className="flex h-8 w-8 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600"
                                >
                                    ‹
                                </button>
                                <button
                                    type="button"
                                    onClick={() => scrollSimilar(1)}
                                    aria-label="Next items"
                                    className="flex h-8 w-8 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600"
                                >
                                    ›
                                </button>
                            </div>
                        </div>
                        <div ref={similarRef} className="scrollbar-none flex gap-3 overflow-x-auto scroll-smooth pb-1">
                            {similar.map((item) => (
                                <button
                                    key={item.uuid}
                                    type="button"
                                    onClick={() => onSwitch(item)}
                                    className="group/similar w-32 shrink-0 overflow-hidden rounded-xl bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    <span className="flex aspect-square items-center justify-center overflow-hidden bg-gray-50">
                                        {item.imageUrl ? (
                                            <img
                                                src={item.imageUrl}
                                                alt={item.name}
                                                loading="lazy"
                                                className="h-full w-full object-cover transition-transform group-hover/similar:scale-105"
                                            />
                                        ) : (
                                            <img src="/images/brand/logo-mark-blue.png" alt="" className="h-10 w-10 opacity-30" />
                                        )}
                                    </span>
                                    <span className="block p-2.5">
                                        <span className="block truncate text-xs text-gray-700">{item.name}</span>
                                        <RatingStars average={item.ratingAverage} count={item.ratingCount} />
                                        <span className="mt-0.5 flex items-baseline gap-1.5">
                                            <span className="text-sm font-bold text-brand-700">
                                                {formatNairaFromKobo(item.priceKobo)}
                                            </span>
                                            {item.compareAtPriceKobo != null &&
                                                item.compareAtPriceKobo > item.priceKobo && (
                                                    <s className="text-[10px] text-gray-400">
                                                        {formatNairaFromKobo(item.compareAtPriceKobo)}
                                                    </s>
                                                )}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
