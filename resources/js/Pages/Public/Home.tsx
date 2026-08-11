import { useAuthModal } from '@/Components/domain/auth/auth-modal-context';
import { CartGlyph, ProductCard, RatingStars } from '@/Components/domain/catalog/ProductCard';
import QuickViewModal from '@/Components/domain/catalog/QuickViewModal';
import Reveal from '@/Components/ui/Reveal';
import PublicLayout from '@/Layouts/PublicLayout';
import { Category, PageProps, ProductSummary } from '@/Types';
import { productLinkProps } from '@/Utils/links';
import { useMoney } from '@/Hooks/useI18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { heroTheme } from '@/Utils/heroThemes';

interface HomeProps {
    categories: Category[];
    featuredProducts: ProductSummary[];
    newestProducts: ProductSummary[];
    campaignProducts: ProductSummary[];
    trendingProducts: ProductSummary[];
    trendingSearches: string[];
    heroSlides: HeroSlideDto[];
    recentOrderCount: number;
    supportHotline: string;
}

const categoryStyle: Record<string, { emoji: string; tile: string }> = {
    electronics: { emoji: '📺', tile: 'bg-sky-100' },
    'home-appliances': { emoji: '🧊', tile: 'bg-emerald-100' },
    'solar-equipment': { emoji: '🔆', tile: 'bg-amber-100' },
    furniture: { emoji: '🛋️', tile: 'bg-orange-100' },
    fashion: { emoji: '👗', tile: 'bg-pink-100' },
    'business-equipment': { emoji: '🖨️', tile: 'bg-violet-100' },
};

// ─── Hero data ──────────────────────────────────────────────────────────────

const SLIDE_DURATION = 5000;

/** Raw slide content as authored on Admin/Merchandising/HeroSlides. */
interface HeroSlideDto {
    eyebrow: string;
    title: string;
    description: string;
    ctaLabel: string;
    ctaTarget: 'auth_gate' | 'catalog' | 'vendor_register';
    theme: string;
    emoji: string;
    offerType: 'from_price' | 'campaign_discount' | 'static';
    offerLabel: string;
    offerValue: string | null;
}

interface ResolvedHeroSlide extends HeroSlideDto {
    resolvedOfferValue: string;
}

/**
 * Turns admin-authored slide copy into slides ready to render, by filling in
 * the one thing this app never lets an admin type by hand: the number.
 *
 * A 'from_price' slide only appears once there is a real cheapest price to
 * show; a 'campaign_discount' slide only appears while a live campaign
 * actually beats the sticker price. No product, no campaign — no slide,
 * rather than a claim with nothing behind it.
 */
function resolveHeroSlides(
    slides: HeroSlideDto[],
    featuredProducts: ProductSummary[],
    campaignProducts: ProductSummary[],
    money: (kobo: number) => string,
): ResolvedHeroSlide[] {
    const cheapestKobo = featuredProducts.length > 0 ? Math.min(...featuredProducts.map((p) => p.priceKobo)) : null;

    const discountPercents = campaignProducts
        .filter((product) => typeof product.compareAtPriceKobo === 'number' && product.compareAtPriceKobo > product.priceKobo)
        .map((product) => Math.round((1 - product.priceKobo / (product.compareAtPriceKobo as number)) * 100));
    const bestDiscount = discountPercents.length > 0 ? Math.max(...discountPercents) : null;

    return slides.flatMap((slide) => {
        if (slide.offerType === 'from_price') {
            return cheapestKobo !== null ? [{ ...slide, resolvedOfferValue: money(cheapestKobo) }] : [];
        }
        if (slide.offerType === 'campaign_discount') {
            return bestDiscount !== null ? [{ ...slide, resolvedOfferValue: `${bestDiscount}% OFF` }] : [];
        }

        return [{ ...slide, resolvedOfferValue: slide.offerValue ?? '' }];
    });
}

// ─── AuthGateAction ──────────────────────────────────────────────────────────

/**
 * A call-to-action that opens the sign-in/register modal for guests and
 * goes straight to the dashboard for signed-in customers.
 */
function AuthGateAction({
    className,
    tabIndex,
    children,
}: {
    className: string;
    tabIndex?: number;
    children: ReactNode;
}) {
    const openAuth = useAuthModal();
    const { auth } = usePage<PageProps>().props;

    if (auth.user) {
        return (
            <Link href={route('dashboard')} tabIndex={tabIndex} className={className}>
                {children}
            </Link>
        );
    }

    return (
        <button type="button" onClick={() => openAuth()} tabIndex={tabIndex} className={className}>
            {children}
        </button>
    );
}

/**
 * A real count of orders placed platform-wide in the last hour — never
 * shown when there is nothing to show, since a "0 orders" banner is worse
 * than no banner at all.
 */
function RecentOrdersBanner({ count }: { count: number }) {
    if (count <= 0) return null;

    return (
        <div className="mt-3 inline-flex items-center gap-3 rounded-xl bg-gradient-to-br from-brand-700 to-brand-900 px-4 py-3 text-white shadow-sm">
            <span className="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-brand-yellow" aria-hidden="true" />
            <span>
                <span className="block text-xl font-extrabold leading-none tabular-nums">{count.toLocaleString()}</span>
                <span className="mt-1 block text-xs text-white/70">
                    order{count === 1 ? '' : 's'} placed in the last hour
                </span>
            </span>
        </div>
    );
}

// ─── Hero sub-components ─────────────────────────────────────────────────────

function secondsUntilMidnight() {
    const now = new Date();
    const midnight = new Date(now);
    midnight.setHours(24, 0, 0, 0);
    return Math.max(0, Math.floor((midnight.getTime() - now.getTime()) / 1000));
}

function secondsUntil(target: string | null | undefined): number {
    if (!target) return 0;
    return Math.max(0, Math.floor((new Date(target).getTime() - Date.now()) / 1000));
}

/** Live seconds remaining until a campaign's real `ends_at`, ticking down. */
function useCountdown(target: string | null | undefined): number {
    const [remaining, setRemaining] = useState(() => secondsUntil(target));

    useEffect(() => {
        setRemaining(secondsUntil(target));
        if (!target) return;
        const id = window.setInterval(() => setRemaining(secondsUntil(target)), 1000);
        return () => window.clearInterval(id);
    }, [target]);

    return remaining;
}

function formatCountdown(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return hours > 0
        ? `${hours}h ${String(minutes).padStart(2, '0')}m left`
        : `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')} left`;
}

/** Live HH:MM:SS chips counting down to midnight — deals reset daily. */
function DealsCountdown() {
    const [seconds, setSeconds] = useState(secondsUntilMidnight);

    useEffect(() => {
        const id = window.setInterval(() => setSeconds(secondsUntilMidnight()), 1000);
        return () => window.clearInterval(id);
    }, []);

    const parts = [
        String(Math.floor(seconds / 3600)).padStart(2, '0'),
        String(Math.floor((seconds % 3600) / 60)).padStart(2, '0'),
        String(seconds % 60).padStart(2, '0'),
    ];

    return (
        <div className="flex items-center gap-1" aria-label="Deals end at midnight">
            <span className="mr-1 hidden text-xs text-white/80 sm:inline">Ends in</span>
            {parts.map((part, i) => (
                <span key={i} className="flex items-center gap-1">
                    {i > 0 && <span className="font-mono text-xs font-bold text-white/70">:</span>}
                    <span className="rounded bg-brand-900 px-1.5 py-1 font-mono text-xs font-bold text-brand-yellow">
                        {part}
                    </span>
                </span>
            ))}
        </div>
    );
}


const PROMO_SEEN_KEY = 'fm.promo-seen';

/**
 * Welcome promo that pops in shortly after the page loads — real cheapest
 * deal, real products. Shown once per browser session so it never nags.
 */
function PromoPopup({ products }: { products: ProductSummary[] }) {
    const { money } = useMoney();
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (products.length === 0) return;
        try {
            if (sessionStorage.getItem(PROMO_SEEN_KEY)) return;
        } catch {
            return;
        }
        const t = setTimeout(() => setOpen(true), 1200);
        return () => clearTimeout(t);
    }, [products.length]);

    function dismiss() {
        setOpen(false);
        try {
            sessionStorage.setItem(PROMO_SEEN_KEY, '1');
        } catch {
            // best effort — worst case it shows again next visit
        }
    }

    useEffect(() => {
        if (!open) return;
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') dismiss();
        }
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    if (!open || products.length === 0) return null;

    const cheapest = products.reduce((a, b) => (b.priceKobo < a.priceKobo ? b : a));

    return (
        <div
            className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/70 p-4"
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) dismiss();
            }}
            role="dialog"
            aria-modal="true"
            aria-label="Today's promo"
        >
            <div className="relative w-full max-w-sm animate-popIn overflow-hidden rounded-3xl bg-white shadow-2xl">
                <button
                    type="button"
                    onClick={dismiss}
                    aria-label="Close promo"
                    className="absolute right-3 top-3 z-10 rounded-full bg-white/20 p-1.5 text-white backdrop-blur-sm transition hover:bg-white/30"
                >
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>

                {/* Header band */}
                <div className="bg-gradient-to-br from-brand-600 to-brand-900 px-6 pb-12 pt-6 text-center text-white">
                    <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-brand-yellow">
                        🔥 Today's picks
                    </p>
                    <p className="mt-2 text-2xl font-extrabold leading-tight">
                        Deals starting from{' '}
                        <span className="text-brand-yellow">{money(cheapest.priceKobo)}</span>
                    </p>
                </div>

                {/* Product peek overlapping the band */}
                <div className="-mt-9 flex justify-center gap-3 px-6">
                    {products.slice(0, 3).map((product) => (
                        <a
                            key={product.uuid}
                            {...productLinkProps(product.slug)}
                            onClick={dismiss}
                            className="block w-20 overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-black/5 transition-transform hover:scale-105"
                        >
                            <ShowcaseImage product={product} />
                        </a>
                    ))}
                </div>

                <div className="px-6 pb-6 pt-4 text-center">
                    <p className="text-sm text-gray-600">
                        Verified vendors, locked prices, FirstMaket delivery nationwide.
                    </p>
                    <Link
                        href={route('catalog.index')}
                        onClick={dismiss}
                        className="mt-4 inline-flex rounded-full bg-brand-600 px-4 py-2.5 text-xs font-bold text-white transition-colors hover:bg-brand-700 sm:px-5 sm:py-3 sm:text-sm"
                    >
                        Grab Now →
                    </Link>
                    <button
                        type="button"
                        onClick={dismiss}
                        className="ml-2 mt-2 inline-flex px-2 py-1 text-xs text-gray-400 transition-colors hover:text-gray-600 sm:text-sm"
                    >
                        Keep browsing
                    </button>
                </div>
            </div>
        </div>
    );
}

/** Amazon/Jumia-style quadrant panel: header + 2x2 mini product grid. */
function ProductPanel({
    title,
    accent,
    href,
    linkLabel,
    products,
    onQuickView,
}: {
    title: string;
    accent: string;
    href: string;
    linkLabel: string;
    products: ProductSummary[];
    onQuickView?: (product: ProductSummary) => void;
}) {
    if (products.length === 0) return null;

    return (
        <div className="overflow-hidden rounded-xl bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                <h2 className="flex items-center gap-2 text-base font-bold text-gray-900">
                    <span aria-hidden="true">{accent}</span> {title}
                </h2>
                <Link href={href} className="text-xs font-semibold text-brand-600 hover:underline">
                    {linkLabel}
                </Link>
            </div>
            <div className="grid grid-cols-2 gap-3 p-4">
                {products.slice(0, 4).map((product) => (
                    <ProductCard key={product.uuid} product={product} onQuickView={onQuickView} />
                ))}
            </div>
        </div>
    );
}

function ShowcaseImage({ product }: { product: ProductSummary }) {
    return (
        <span className="flex aspect-square w-full items-center justify-center bg-gray-50">
            {product.imageUrl ? (
                <img
                    src={product.imageUrl}
                    alt={product.name}
                    loading="lazy"
                    className="h-full w-full object-cover"
                />
            ) : (
                <img src="/images/brand/logo-mark-blue.png" alt="" className="h-10 w-10 opacity-30" />
            )}
        </span>
    );
}

/** Routes a slide's button by its admin-chosen destination, not one fixed behavior for every slide. */
function HeroCta({ target, tabIndex, className, children }: { target: HeroSlideDto['ctaTarget']; tabIndex?: number; className: string; children: ReactNode }) {
    if (target === 'catalog') {
        return <Link href={route('catalog.index')} tabIndex={tabIndex} className={className}>{children}</Link>;
    }
    if (target === 'vendor_register') {
        return <Link href={route('vendor.register')} tabIndex={tabIndex} className={className}>{children}</Link>;
    }

    return <AuthGateAction tabIndex={tabIndex} className={className}>{children}</AuthGateAction>;
}

function HeroCarousel({ products, slides: heroSlides }: { products: ProductSummary[]; slides: ResolvedHeroSlide[] }) {
    const { money } = useMoney();
    const [current, setCurrent] = useState(0);
    // Progress only drives the auto-advance timing now (dots replaced the rail).
    const [, setProgress] = useState(0);
    const [paused, setPaused] = useState(false);
    const tickRef = useRef<number | null>(null);

    // The flash-sale slide only exists when a live campaign backs it, so the
    // slide count can change between loads — keep `current` in range.
    useEffect(() => {
        if (current >= heroSlides.length) setCurrent(0);
    }, [heroSlides.length, current]);

    // Three real products per slide (hot deals / new items) so shoppers see
    // merchandise, not just a slogan. Wraps around when the catalog is small
    // so every slide has items; falls back to the emoji only when empty.
    const pick = (offset: number) =>
        products.length === 0
            ? []
            : Array.from(
                  { length: Math.min(3, products.length) },
                  (_, k) => products[(offset + k) % products.length],
              );
    const showcases = heroSlides.map((_, i) => pick(i * 3));

    function goTo(i: number) {
        setCurrent(((i % heroSlides.length) + heroSlides.length) % heroSlides.length);
        setProgress(0);
    }

    useEffect(() => {
        if (paused) return;
        tickRef.current = window.setInterval(() => {
            setProgress((p) => {
                if (p + 50 >= SLIDE_DURATION) {
                    setCurrent((c) => (c + 1) % heroSlides.length);
                    return 0;
                }
                return p + 50;
            });
        }, 50);
        return () => {
            if (tickRef.current) window.clearInterval(tickRef.current);
        };
    }, [paused, heroSlides.length]);

    return (
        <div
            className="group relative h-full min-h-[420px] min-w-0 overflow-hidden rounded-xl"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
        >
            {heroSlides.map((slide, i) => {
                const showcase = showcases[i] ?? [];
                const hero = showcase[0];
                const theme = heroTheme(slide.theme);

                return (
                    <div
                        key={i}
                        className={`absolute inset-0 grid grid-cols-1 items-stretch bg-gradient-to-br transition-opacity duration-700 sm:grid-cols-[1.15fr_0.85fr] ${
                            theme.bg
                        } ${i === current ? 'z-[1] opacity-100' : 'pointer-events-none opacity-0'}`}
                        aria-hidden={i !== current}
                    >
                        {/* Soft brand glow — FirstMaket signature, not a stock template */}
                        <span
                            className="pointer-events-none absolute -left-16 -top-16 h-56 w-56 rounded-full bg-brand-yellow/10 blur-3xl"
                            aria-hidden="true"
                        />

                        <div className="flex h-full flex-col justify-center px-5 pb-12 pt-6 text-white sm:px-10">
                            <p className="mb-3 text-[11px] font-bold uppercase tracking-[0.14em] text-brand-yellow">
                                {slide.eyebrow}
                            </p>
                            <h1 className="mb-3 max-w-[440px] text-[34px] font-extrabold leading-[1.08] tracking-tight sm:text-[40px]">
                                {slide.title}
                            </h1>
                            <p className="max-w-[380px] text-[15px] leading-relaxed text-white/80">
                                {slide.description}
                            </p>

                            {/* Offer chip + CTA: one aligned flex row, pushed toward
                                the bottom of the slide */}
                            <div className="mt-8 flex w-full flex-nowrap items-center justify-center gap-2 sm:justify-start sm:gap-3">
                                <div className="inline-flex shrink items-center gap-1.5 rounded-xl border-2 border-dashed border-white/60 bg-brand-yellow px-2.5 py-2 shadow-[0_4px_0_rgba(16,42,94,0.45)] sm:shrink-0 sm:gap-2.5 sm:px-4 sm:py-2.5">
                                    <span className="text-[8px] font-bold uppercase leading-tight tracking-wide text-brand-800 sm:text-[11px]">
                                        {slide.offerLabel}
                                    </span>
                                    <span className="truncate text-sm font-extrabold leading-none tracking-tight text-brand-900 sm:text-xl">
                                        {slide.resolvedOfferValue}
                                    </span>
                                </div>
                                <HeroCta
                                    target={slide.ctaTarget}
                                    tabIndex={i === current ? 0 : -1}
                                    className={`inline-flex shrink-0 whitespace-nowrap rounded-full px-3 py-2 text-[10px] font-bold transition-colors sm:px-6 sm:py-3 sm:text-sm ${theme.btnClass}`}
                                >
                                    {slide.ctaLabel}
                                </HeroCta>
                            </div>
                        </div>

                        {/* Full-bleed product image with overlaid price/name */}
                        {hero ? (
                            <a
                                {...productLinkProps(hero.slug)}
                                tabIndex={i === current ? 0 : -1}
                                className="group/hero relative hidden h-full overflow-hidden sm:block"
                            >
                                {hero.imageUrl ? (
                                    <img loading="lazy" decoding="async"
                                        src={hero.imageUrl}
                                        alt={hero.name}
                                        className="h-full w-full object-cover transition-transform duration-500 group-hover/hero:scale-105"
                                    />
                                ) : (
                                    <span className="flex h-full w-full items-center justify-center bg-white/10 text-[110px] opacity-70">
                                        {slide.emoji}
                                    </span>
                                )}

                                {/* Blend the image into the blue text panel */}
                                <span
                                    className="pointer-events-none absolute inset-y-0 left-0 w-2/5 bg-gradient-to-r from-brand-800/90 to-transparent"
                                    aria-hidden="true"
                                />
                                <span
                                    className="pointer-events-none absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-brand-900/80 to-transparent"
                                    aria-hidden="true"
                                />

                                {/* Floating product info bar (glass) */}
                                <span className="absolute bottom-5 left-5 right-5 flex items-center gap-3 rounded-2xl bg-white/95 p-3.5 shadow-xl shadow-brand-900/30 backdrop-blur-md">
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-semibold text-gray-900">
                                            {hero.name}
                                        </span>
                                        <RatingStars average={hero.ratingAverage} count={hero.ratingCount} />
                                        <span className="mt-0.5 flex flex-wrap items-baseline gap-x-2">
                                            <span className="text-lg font-extrabold leading-tight text-brand-700">
                                                {money(hero.priceKobo)}
                                            </span>
                                            {hero.compareAtPriceKobo != null &&
                                                hero.compareAtPriceKobo > hero.priceKobo && (
                                                    <s className="text-xs text-gray-400">
                                                        {money(hero.compareAtPriceKobo)}
                                                    </s>
                                                )}
                                        </span>
                                    </span>
                                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg transition-colors group-hover/hero:bg-brand-700">
                                        <CartGlyph className="h-5 w-5" />
                                    </span>
                                </span>
                            </a>
                        ) : (
                            <div className="flex h-full items-center justify-center text-[110px] opacity-70">
                                {slide.emoji}
                            </div>
                        )}
                    </div>
                );
            })}

            {/* Slide dots (horizontal, like the flash-deal indicator).

                The dot itself is still 8px; the button around it is 24px,
                because a finger is nowhere near accurate enough for an 8px
                target — it was the one control on this page a phone user
                could not reliably hit.

                The hit areas now sit edge to edge and provide the spacing,
                so the explicit gap is gone. The dots end up marginally
                further apart than before; everything else is unchanged. */}
            <div className="absolute bottom-3 left-1/2 z-[2] flex -translate-x-1/2 items-center">
                {heroSlides.map((_, i) => (
                    <button
                        key={i}
                        aria-label={`Go to slide ${i + 1}`}
                        aria-current={i === current}
                        onClick={() => goTo(i)}
                        className="flex h-6 w-6 items-center justify-center"
                    >
                        <span
                            className={`block h-2 rounded-full transition-all duration-300 ${
                                i === current ? 'w-6 bg-white' : 'w-2 bg-white/40 hover:bg-white/70'
                            }`}
                        />
                    </button>
                ))}
            </div>
        </div>
    );
}

/** Rotating spotlight over products in a live campaign — real deal price, real countdown. */
function FlashSpotlight({ products }: { products: ProductSummary[] }) {
    const { money } = useMoney();
    const [index, setIndex] = useState(0);

    // campaignEndsAt is only set by HomeDataService when this product's
    // priceKobo actually reflects a live, cheaper campaign price.
    const saleProducts = products.filter((product) => product.campaignEndsAt != null);

    // Rotate to the next deal every few seconds, wrapping around.
    useEffect(() => {
        if (saleProducts.length < 2) return;
        const id = window.setInterval(() => setIndex((i) => (i + 1) % saleProducts.length), 5000);
        return () => window.clearInterval(id);
    }, [saleProducts.length]);

    const product = saleProducts[index % Math.max(saleProducts.length, 1)];
    const remaining = useCountdown(product?.campaignEndsAt);
    if (!product) return null;

    return (
        <a
            {...productLinkProps(product.slug)}
            className="group flex flex-1 items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition-colors hover:border-brand-300"
        >
            <div key={product.uuid} className="flex min-w-0 flex-1 animate-countUp items-center gap-4">
                {product.imageUrl ? (
                    <img loading="lazy" decoding="async"
                        src={product.imageUrl}
                        alt=""
                        className="h-20 w-20 shrink-0 rounded-xl object-cover"
                    />
                ) : (
                    <div
                        className={`flex h-20 w-20 shrink-0 items-center justify-center rounded-xl text-3xl ${
                            categoryStyle[product.categorySlug]?.tile ?? 'bg-gray-100'
                        }`}
                    >
                        {categoryStyle[product.categorySlug]?.emoji ?? '🛍️'}
                    </div>
                )}
                <div className="min-w-0">
                    <p className="mb-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px] font-bold uppercase tracking-wide text-orange-500">
                        <span>⚡ Flash deal</span>
                        {remaining > 0 && (
                            <span className="rounded bg-orange-50 px-1.5 py-0.5 font-mono text-[10px] normal-case text-orange-600">
                                {formatCountdown(remaining)}
                            </span>
                        )}
                    </p>
                    <p className="truncate text-[15px] font-semibold text-gray-900 group-hover:text-brand-700">
                        {product.name}
                    </p>
                    <p className="flex flex-wrap items-baseline gap-x-2">
                        <span className="font-mono text-lg font-bold text-brand-600">
                            {money(product.priceKobo)}
                        </span>
                        {product.compareAtPriceKobo != null && product.compareAtPriceKobo > product.priceKobo && (
                            <s className="text-xs text-gray-400">{money(product.compareAtPriceKobo)}</s>
                        )}
                    </p>
                </div>
            </div>

            {/* Rotation dots */}
            {saleProducts.length > 1 && (
                <span className="flex shrink-0 flex-col gap-1" aria-hidden="true">
                    {saleProducts.slice(0, Math.min(saleProducts.length, 5)).map((p, dotIndex) => (
                        <span
                            key={p.uuid}
                            className={`h-1.5 w-1.5 rounded-full transition-colors ${
                                dotIndex === index % Math.min(saleProducts.length, 5)
                                    ? 'bg-brand-600'
                                    : 'bg-gray-200'
                            }`}
                        />
                    ))}
                </span>
            )}
        </a>
    );
}

function TrendingTicker({ products, searches }: { products: ProductSummary[]; searches: string[] }) {
    const { money } = useMoney();
    const doubled = searches.length > 0 ? [...searches, ...searches] : [];
    const [offset, setOffset] = useState(0);

    // Rotate the 3-product window so shoppers see the whole catalog over time.
    useEffect(() => {
        if (products.length <= 3) return;
        const id = window.setInterval(() => setOffset((o) => (o + 3) % products.length), 6000);
        return () => window.clearInterval(id);
    }, [products.length]);

    const visible =
        products.length === 0
            ? []
            : Array.from(
                  { length: Math.min(3, products.length) },
                  (_, k) => products[(offset + k) % products.length],
              );

    // Nothing real to show yet (fresh install, no searches logged) — no card
    // rather than a shell with fabricated content inside it.
    if (doubled.length === 0 && visible.length === 0) return null;

    return (
        <div className="min-w-0 max-w-full overflow-hidden rounded-xl border border-gray-200 bg-white p-3.5">
            {doubled.length > 0 && (
                <>
                    <p className="mb-2 px-0.5 text-[10.5px] font-bold uppercase tracking-wide text-gray-400">
                        Trending searches
                    </p>
                    <div className="group flex overflow-hidden">
                        <div className="flex animate-marquee gap-2 whitespace-nowrap pr-2 group-hover:[animation-play-state:paused]">
                            {doubled.map((term, i) => (
                                <Link
                                    key={`${term}-${i}`}
                                    href={route('catalog.index', { query: term })}
                                    className="rounded-full bg-brand-50 px-3 py-1.5 text-xs font-medium text-brand-700 transition-colors hover:bg-brand-100"
                                >
                                    {term}
                                </Link>
                            ))}
                        </div>
                    </div>
                </>
            )}

            {/* What those searches lead to — real items, then the catalog */}
            {visible.length > 0 && (
                <div className={doubled.length > 0 ? 'mt-3 border-t border-gray-100 pt-3' : ''}>
                    <div key={offset} className="grid animate-countUp grid-cols-3 gap-2">
                        {visible.map((product) => (
                            <a
                                key={product.uuid}
                                {...productLinkProps(product.slug)}
                                className="group/item min-w-0"
                            >
                                <div className="flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-gray-50">
                                    {product.imageUrl ? (
                                        <img
                                            src={product.imageUrl}
                                            alt={product.name}
                                            loading="lazy"
                                            className="h-full w-full object-cover transition-transform group-hover/item:scale-105"
                                        />
                                    ) : (
                                        <img
                                            src="/images/brand/logo-mark-blue.png"
                                            alt=""
                                            className="h-8 w-8 opacity-30"
                                        />
                                    )}
                                </div>
                                <p className="mt-1.5 truncate text-xs font-medium text-gray-700 group-hover/item:text-brand-600">
                                    {product.name}
                                </p>
                                <p className="text-sm font-bold text-brand-700">
                                    {money(product.priceKobo)}
                                </p>
                            </a>
                        ))}
                    </div>
                    <Link
                        href={route('catalog.index')}
                        className="mx-auto mt-2 inline-flex items-center justify-center gap-1 rounded-full bg-brand-50 px-3 py-1.5 text-[11px] font-semibold text-brand-700 transition-colors hover:bg-brand-100"
                    >
                        and many more →
                    </Link>
                </div>
            )}
        </div>
    );
}

export default function Home({ categories, featuredProducts, newestProducts, campaignProducts = [], trendingProducts = [], trendingSearches = [], heroSlides = [], recentOrderCount = 0, supportHotline }: HomeProps) {
    const [quickView, setQuickView] = useState<ProductSummary | null>(null);
    const dealsRef = useRef<HTMLDivElement>(null);
    const { money } = useMoney();

    function scrollDeals(direction: 1 | -1) {
        const el = dealsRef.current;
        el?.scrollBy({ left: direction * el.clientWidth * 0.8, behavior: 'smooth' });
    }

    // Candidate pool for the quick-view "Similar items" row.
    const quickViewPool = [...featuredProducts, ...newestProducts].filter(
        (product, index, all) => all.findIndex((p) => p.uuid === product.uuid) === index,
    );

    const resolvedHeroSlides = resolveHeroSlides(heroSlides, featuredProducts, campaignProducts, money);

    return (
        <PublicLayout categories={categories}>
            <Head>
                <title>FirstMaket- Just Order. We Deliver</title>
                <meta
                    name="description"
                    content="Nigeria's goal-based marketplace. Pay at once or save small small toward electronics, appliances, solar, furniture, fashion and business equipment - verified vendors, locked prices, FirstMaket delivery."
                />
            </Head>

            <PromoPopup products={featuredProducts} />
            {quickView && (
                <QuickViewModal
                    product={quickView}
                    pool={quickViewPool}
                    onSwitch={setQuickView}
                    onClose={() => setQuickView(null)}
                />
            )}

            <div className="mx-auto max-w-7xl px-4">
                {/* -- Security reminder (Temu pattern) — honest trust signal -- */}
                <div className="mt-3 flex items-center justify-between gap-3 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-2.5 text-xs text-emerald-800 sm:text-sm">
                    <p className="flex min-w-0 items-center gap-2">
                        <span aria-hidden="true">🔔</span>
                        <span className="truncate sm:whitespace-normal">
                            Security reminder: FirstMaket will never ask for extra fees by SMS or email —
                            pay only through Paystack checkout.
                        </span>
                    </p>
                    <a href="#footer-help" className="shrink-0 font-semibold hover:underline">
                        View →
                    </a>
                </div>

                <RecentOrdersBanner count={recentOrderCount} />

                {/* -- Hero: Carousel + right rail + promo cards + category dock -- */}
                <section className="mt-4" aria-label="Hero">
                    {/* FirstMaket's own hero: wide carousel + live rail — no
                        category sidebar (that lives in the mega menu + dock).
                        minmax(0,…) + min-w-0: the marquee's nowrap track must
                        never dictate a column width. */}
                    <div className={`grid gap-4 ${resolvedHeroSlides.length > 0 ? 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]' : ''}`}>
                        {resolvedHeroSlides.length > 0 && (
                            <HeroCarousel products={featuredProducts} slides={resolvedHeroSlides} />
                        )}

                        {/* Right rail */}
                        <div className="flex min-w-0 flex-col gap-3">
                            <FlashSpotlight products={campaignProducts} />
                            <TrendingTicker
                                products={trendingProducts.length ? trendingProducts : newestProducts}
                                searches={trendingSearches}
                            />
                        </div>
                    </div>

                    {/* Category dock */}
                    <div className="mt-4">
                        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                            <div className="flex items-center gap-2 px-4 py-3">
                                {categories.map((cat) => {
                                    const style = categoryStyle[cat.slug] ?? { emoji: '🛍️', tile: 'bg-gray-100' };
                                    return (
                                        <Link
                                            key={cat.slug}
                                            href={route('catalog.index', { category: cat.slug })}
                                            className="flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-gray-200 px-3.5 py-2 transition hover:border-brand-400 hover:bg-brand-50"
                                        >
                                            <span className="text-base">{style.emoji}</span>
                                            <span className="text-xs font-medium text-gray-600">{cat.name}</span>
                                        </Link>
                                    );
                                })}
                                <Link
                                    href={route('catalog.index')}
                                    className="flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-brand-700"
                                >
                                    All categories →
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>

                {/* -- Super Deals strip (AliExpress / Temu style) -- */}
                <section aria-label="Super deals" className="mt-4">
                    <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 bg-gradient-to-r from-brand-600 to-brand-800 px-5 py-3.5">
                            <span className="text-2xl" aria-hidden="true">⚡</span>
                            <div className="mr-2">
                                <h2 className="text-lg font-extrabold text-white">Super Deals</h2>
                                <p className="text-[11px] text-brand-200">Today's best prices — reset daily</p>
                            </div>
                            <DealsCountdown />
                            <Link
                                href={route('catalog.index')}
                                className="ml-auto whitespace-nowrap rounded-full border border-white/30 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-white/10"
                            >
                                View All →
                            </Link>
                        </div>
                        {/* Scrollable deal carousel with prev/next arrows */}
                        <div className="group/deals relative">
                            <div ref={dealsRef} className="scrollbar-none flex gap-3 overflow-x-auto scroll-smooth p-4">
                                {featuredProducts.slice(0, 10).map((product) => (
                                    <div key={product.uuid} className="w-60 shrink-0">
                                        <ProductCard product={product} badge="DEAL" onQuickView={setQuickView} />
                                    </div>
                                ))}
                                <Link
                                    href={route('catalog.index')}
                                    className="flex w-24 shrink-0 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-200 text-gray-400 transition-colors hover:border-brand-300 hover:text-brand-600"
                                >
                                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-50 text-lg text-brand-600">→</span>
                                    <span className="text-[11px] font-medium">See more</span>
                                </Link>
                            </div>

                            <button
                                type="button"
                                onClick={() => scrollDeals(-1)}
                                aria-label="Previous deals"
                                className="absolute left-2 top-1/2 z-10 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full border border-gray-100 bg-white text-base text-gray-700 shadow-lg transition hover:text-brand-600 sm:h-10 sm:w-10 sm:text-lg sm:opacity-0 sm:group-hover/deals:opacity-100"
                            >
                                ‹
                            </button>
                            <button
                                type="button"
                                onClick={() => scrollDeals(1)}
                                aria-label="Next deals"
                                className="absolute right-2 top-1/2 z-10 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full border border-gray-100 bg-white text-base text-gray-700 shadow-lg transition hover:text-brand-600 sm:h-10 sm:w-10 sm:text-lg sm:opacity-0 sm:group-hover/deals:opacity-100"
                            >
                                ›
                            </button>
                        </div>
                    </div>
                </section>

                {/* -- Dual panels: New Arrivals / Top Picks (Amazon / Jumia) -- */}
                <section aria-label="New arrivals and top picks" className="mt-4 grid gap-4 lg:grid-cols-2">
                    <ProductPanel
                        title="New Arrivals"
                        accent="✨"
                        href={route('catalog.index', { sort: 'newest' })}
                        linkLabel="See all new →"
                        products={newestProducts}
                        onQuickView={setQuickView}
                    />
                    <ProductPanel
                        title="Top Picks for You"
                        accent="⭐"
                        href={route('catalog.index')}
                        linkLabel="Browse all →"
                        products={featuredProducts}
                        onQuickView={setQuickView}
                    />
                </section>

                {/* -- Shop by Category (Amazon / Jumia tiles) -- */}
                <section aria-label="Shop by category" className="mt-4">
                    <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                            <h2 className="text-lg font-bold text-gray-900">Shop by Category</h2>
                            <Link href={route('catalog.index')} className="text-sm font-medium text-brand-600 hover:underline">
                                See all →
                            </Link>
                        </div>
                        <div className="grid grid-cols-3 divide-x divide-y divide-gray-100 sm:grid-cols-4 lg:grid-cols-6">
                            {categories.map((category) => {
                                const style = categoryStyle[category.slug] ?? { emoji: '🛍️', tile: 'bg-gray-100' };
                                return (
                                    <Link
                                        key={category.slug}
                                        href={route('catalog.index', { category: category.slug })}
                                        className="group flex flex-col items-center gap-2 p-4 transition hover:bg-brand-50"
                                    >
                                        <div
                                            className={`flex h-14 w-14 items-center justify-center rounded-full ${style.tile} text-3xl transition group-hover:scale-110`}
                                        >
                                            {style.emoji}
                                        </div>
                                        <span className="text-center text-xs font-medium text-gray-700 group-hover:text-brand-700">
                                            {category.name}
                                        </span>
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                </section>

                {/* -- More to Love (Temu / AliExpress grid) -- */}
                <section aria-label="More to love" className="mt-4">
                    <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
                            <div>
                                <h2 className="text-lg font-bold text-gray-900">More to Love</h2>
                                <p className="text-sm text-gray-500">Recommended products just for you</p>
                            </div>
                            <AuthGateAction className="inline-flex rounded-full bg-brand-600 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-brand-700 sm:px-4 sm:py-2 sm:text-xs">
                                Unlock Personalised Offers
                            </AuthGateAction>
                        </div>
                        <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                            {newestProducts.slice(0, 8).map((product) => (
                                <ProductCard key={product.uuid} product={product} onQuickView={setQuickView} />
                            ))}
                        </div>
                    </div>
                </section>

                {/* -- Full-width sell CTA banner (Konga / Jumia style) -- */}
                <section aria-label="Sell on FirstMaket" className="mt-10">
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-brand-800 via-brand-700 to-brand-900 px-6 py-12 sm:px-12 lg:py-16">
                        {/* Decorative glows + oversize watermark */}
                        <div
                            className="pointer-events-none absolute -right-16 -top-24 h-72 w-72 rounded-full bg-brand-yellow/15 blur-3xl"
                            aria-hidden="true"
                        />
                        <div
                            className="pointer-events-none absolute -bottom-24 -left-16 h-72 w-72 rounded-full bg-brand-400/10 blur-3xl"
                            aria-hidden="true"
                        />
                        <span
                            className="pointer-events-none absolute -bottom-8 right-6 select-none text-[11rem] leading-none opacity-10 lg:text-[15rem]"
                            aria-hidden="true"
                        >
                            🏪
                        </span>

                        <div className="relative z-[1] grid items-center gap-10 lg:grid-cols-[1.2fr_auto]">
                            <Reveal className="max-w-2xl">
                                <p className="mb-3 inline-flex items-center gap-2 rounded-full border border-brand-yellow/40 bg-brand-yellow/10 px-4 py-1.5 text-[11px] font-bold uppercase tracking-[0.18em] text-brand-yellow">
                                    Grow your business
                                </p>
                                <h2 className="text-3xl font-extrabold leading-tight tracking-tight text-white sm:text-4xl lg:text-5xl">
                                    Sell to customers across Nigeria
                                </h2>
                                <p className="mt-4 max-w-xl text-base leading-relaxed text-white/80">
                                    Zero listing fees, instant Paystack payouts, and FirstMaket handles the
                                    delivery. Verified vendors only — your store, our logistics.
                                </p>
                            </Reveal>
                            <Reveal delay={200} className="flex flex-wrap items-center gap-4 lg:flex-col lg:items-stretch">
                                <Link
                                    href={route('vendor.register')}
                                    className="inline-flex rounded-full bg-brand-yellow px-4 py-2.5 text-center text-xs font-bold text-brand-900 shadow-lg shadow-brand-900/30 transition hover:-translate-y-0.5 hover:bg-yellow-300 hover:shadow-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white active:scale-95 sm:px-8 sm:py-3.5 sm:text-sm"
                                >
                                    Start Selling →
                                </Link>
                                <AuthGateAction
                                    tabIndex={0}
                                    className="inline-flex rounded-full border border-white/40 px-4 py-2.5 text-center text-xs font-semibold text-white transition hover:-translate-y-0.5 hover:border-white hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-yellow active:scale-95 sm:px-8 sm:py-3.5 sm:text-sm"
                                >
                                    Shop Deals
                                </AuthGateAction>
                            </Reveal>
                        </div>
                    </div>
                </section>

                {/* -- How it works -- */}
                <section aria-label="How FirstMaket works" className="mt-10">
                    <Reveal className="mx-auto max-w-2xl text-center">
                        <h2 className="text-2xl font-extrabold tracking-tight text-gray-900 sm:text-3xl">
                            How It Works
                        </h2>
                        <p className="mt-3 text-sm leading-relaxed text-gray-500 sm:text-base">
                            FirstMaket brings you a modern, trusted shopping experience with verified
                            vendors, secure checkout, and delivery across Nigeria.
                        </p>
                    </Reveal>

                    <div className="relative mt-8 grid gap-4 sm:grid-cols-3 sm:gap-6">
                        {/* Connector line behind the step cards (desktop only) */}
                        <div
                            className="pointer-events-none absolute left-[16%] right-[16%] top-12 hidden border-t-2 border-dashed border-brand-200 sm:block"
                            aria-hidden="true"
                        />
                        {[
                            {
                                step: '1',
                                title: 'Choose a product',
                                text: 'Browse verified listings across electronics, fashion, home and business essentials.',
                            },
                            {
                                step: '2',
                                title: 'Pay now or save',
                                text: 'Buy immediately or lock the price and pay over time with a savings plan.',
                            },
                            {
                                step: '3',
                                title: 'Receive delivery',
                                text: 'We handle delivery and protect your details from vendors at every stage.',
                            },
                        ].map((item, index) => (
                            <Reveal key={item.step} delay={index * 150}>
                                <div className="group relative h-full rounded-2xl border border-gray-100 bg-white p-8 text-center shadow-sm transition duration-300 hover:-translate-y-1.5 hover:border-brand-200 hover:shadow-xl hover:shadow-brand-600/10">
                                    <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-brand-600 to-brand-800 text-xl font-extrabold text-brand-yellow shadow-lg shadow-brand-600/25 transition-transform duration-300 group-hover:scale-110 group-hover:rotate-6">
                                        {item.step}
                                    </span>
                                    <h3 className="mt-5 text-lg font-bold text-gray-900 transition-colors group-hover:text-brand-700">
                                        {item.title}
                                    </h3>
                                    <p className="mt-2 text-sm leading-relaxed text-gray-600">{item.text}</p>
                                </div>
                            </Reveal>
                        ))}
                    </div>
                </section>

                {/* Trust strip - Jumia / Amazon trust bar */}
                <section aria-label="Why trust FirstMaket" className="mt-10">
                    <div className="overflow-hidden rounded-2xl bg-gradient-to-br from-brand-800 to-brand-900 p-8 shadow-sm sm:p-10">
                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                            {[
                                { icon: '🔒', title: 'Secure payments', text: 'Every kobo goes through Paystack.' },
                                { icon: '✅', title: 'Verified vendors', text: 'CAC business registration checked before selling.' },
                                { icon: '🚚', title: 'FirstMaket delivery', text: 'We deliver; vendors never see your details.' },
                                { icon: '💬', title: 'Real support', text: `Hotline ${supportHotline}, tickets and WhatsApp.` },
                            ].map((item, index) => (
                                <Reveal key={item.title} delay={index * 120}>
                                    <div className="group h-full rounded-xl border border-white/10 bg-white/5 p-6 backdrop-blur-sm transition duration-300 hover:-translate-y-1 hover:border-brand-yellow/40 hover:bg-white/10 hover:shadow-lg hover:shadow-brand-900/40">
                                        <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-yellow/15 text-xl transition-transform duration-300 group-hover:scale-110">
                                            {item.icon}
                                        </span>
                                        <h3 className="mt-4 font-bold text-brand-yellow">{item.title}</h3>
                                        <p className="mt-1.5 text-sm leading-relaxed text-brand-100">{item.text}</p>
                                    </div>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>
            </div>
        </PublicLayout>
    );
}
