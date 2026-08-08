import CartDrawer, { CartDrawerData } from '@/Components/domain/cart/CartDrawer';
import BuyBoxPolicies from '@/Components/domain/catalog/BuyBoxPolicies';
import { ProductCard, RatingStars } from '@/Components/domain/catalog/ProductCard';
import QuickViewModal from '@/Components/domain/catalog/QuickViewModal';
import VideoPlayer, { ProductVideo } from '@/Components/domain/catalog/VideoPlayer';
import QuantityStepper from '@/Components/ui/QuantityStepper';
import Reveal from '@/Components/ui/Reveal';
import { useAddToCart } from '@/Hooks/useAddToCart';
import PublicLayout from '@/Layouts/PublicLayout';
import { Category, ProductSummary } from '@/Types';
import { useMoney, useTranslation } from '@/Hooks/useI18n';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Heart, ShieldCheck, ShoppingBag, Store, Truck, Undo2, Zap } from 'lucide-react';
import { useEffect, useState } from 'react';

interface ProductShowProps {
    product: {
        uuid: string;
        name: string;
        slug: string;
        description: string;
        priceKobo: number;
        compareAtPriceKobo: number | null;
        ratingAverage: number | null;
        ratingCount: number;
        stockQuantity: number;
        vendorName: string;
        category: { name: string; slug: string };
        images: { id: number; url: string }[];
        /** Vendor answers to the admin-defined fields for this category. */
        specifications: {
            label: string;
            value: string;
            /** Set for list fields, so the page draws a <ul> or an <ol>. */
            listStyle?: 'bullet' | 'numbered' | null;
            items?: string[];
        }[];
        /** Null unless the vendor added a link the page can embed. */
        video: ProductVideo | null;
    };
    relatedProducts: ProductSummary[];
    moreToLove: ProductSummary[];
    cart: CartDrawerData;
    freeShippingThresholdKobo: number;
    categories: Category[];
}

export default function ProductShow({
    product,
    relatedProducts,
    moreToLove,
    cart,
    freeShippingThresholdKobo,
    categories,
}: ProductShowProps) {
    const { t } = useTranslation();
    const { money } = useMoney();
    const [activeImage, setActiveImage] = useState(0);
    const [quantity, setQuantity] = useState(1);
    const [zoom, setZoom] = useState<{ x: number; y: number } | null>(null);
    const [quickView, setQuickView] = useState<ProductSummary | null>(null);
    const { addToCart, adding } = useAddToCart();

    // Reset the gallery when navigating between products.
    useEffect(() => {
        setActiveImage(0);
        setQuantity(1);
    }, [product.uuid]);

    const maxQuantity = Math.max(product.stockQuantity, 1);
    const inStock = product.stockQuantity > 0;
    const lowStock = inStock && product.stockQuantity <= 5;
    const hasDiscount =
        product.compareAtPriceKobo !== null && product.compareAtPriceKobo > product.priceKobo;
    const savingsPercent = hasDiscount
        ? Math.round((1 - product.priceKobo / (product.compareAtPriceKobo as number)) * 100)
        : 0;

    const activeUrl = product.images[activeImage]?.url ?? product.images[0]?.url;

    // Amount summary for the quantity currently selected — what this order
    // would cost on its own, before whatever else is already in the cart.
    const lineSubtotalKobo = product.priceKobo * quantity;
    const lineWasKobo = (product.compareAtPriceKobo ?? product.priceKobo) * quantity;
    const lineSavingKobo = lineWasKobo - lineSubtotalKobo;
    const qualifiesFreeShipping = lineSubtotalKobo >= freeShippingThresholdKobo;

    return (
        <PublicLayout categories={categories}>
            <Head>
                <title>{`${product.name} — FirstMaket`}</title>
                <meta
                    name="description"
                    content={`Buy ${product.name} on FirstMaket— pay at once or save small small at a locked price. ${money(product.priceKobo)}.`}
                />
            </Head>

            {quickView && (
                <QuickViewModal
                    product={quickView}
                    pool={relatedProducts}
                    onSwitch={setQuickView}
                    onClose={() => setQuickView(null)}
                />
            )}

            {/* Right-edge pull tab; the panel slides in over the page. */}
            <CartDrawer cart={cart} />

            <div className="mx-auto max-w-7xl px-4 pb-12">
                {/* Breadcrumb */}
                <nav className="flex flex-wrap items-center gap-1 py-4 text-sm text-gray-500" aria-label="Breadcrumb">
                    <Link href={route('home')} className="transition-colors hover:text-brand-600">
                        Home
                    </Link>
                    <ChevronRight className="h-3.5 w-3.5 text-gray-300" />
                    <Link
                        href={route('catalog.index', { category: product.category.slug })}
                        className="transition-colors hover:text-brand-600"
                    >
                        {product.category.name}
                    </Link>
                    <ChevronRight className="h-3.5 w-3.5 text-gray-300" />
                    <span className="truncate font-medium text-gray-700">{product.name}</span>
                </nav>

                {/* Two columns, not three. The buy box is `sticky`, and sticky
                    only travels inside its own grid cell — with the long
                    description, specs and reviews in a *sibling* column the box
                    used to unstick the moment the short middle column ended.
                    Everything scrollable now shares one tall left column, so
                    the box stays pinned the whole way down. */}
                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                    {/* ── Everything that scrolls ── */}
                    <div className="min-w-0">
                        <div className="grid gap-6 md:grid-cols-2">
                    {/* ── Gallery ── */}
                    <div className="md:sticky md:top-24 md:self-start">
                        <div
                            className={`relative flex aspect-square items-center justify-center overflow-hidden rounded-2xl border border-gray-100 bg-white ${
                                activeUrl ? 'cursor-zoom-in' : ''
                            }`}
                            onMouseMove={(e) => {
                                if (!activeUrl) return;
                                const rect = e.currentTarget.getBoundingClientRect();
                                setZoom({
                                    x: ((e.clientX - rect.left) / rect.width) * 100,
                                    y: ((e.clientY - rect.top) / rect.height) * 100,
                                });
                            }}
                            onMouseLeave={() => setZoom(null)}
                        >
                            {hasDiscount && (
                                <span className="absolute left-3 top-3 z-10 rounded-full bg-red-500 px-2.5 py-1 text-xs font-bold text-white shadow">
                                    -{savingsPercent}%
                                </span>
                            )}
                            {activeUrl ? (
                                <img
                                    src={activeUrl}
                                    alt={product.name}
                                    style={
                                        zoom
                                            ? { transformOrigin: `${zoom.x}% ${zoom.y}%`, transform: 'scale(1.8)' }
                                            : undefined
                                    }
                                    className="h-full w-full object-contain transition-transform duration-150 ease-out"
                                />
                            ) : (
                                <img src="/images/brand/logo-mark-blue.png" alt="" className="h-24 w-24 opacity-30" />
                            )}
                        </div>
                        {product.images.length > 1 && (
                            <div className="scrollbar-none mt-3 flex gap-2 overflow-x-auto">
                                {product.images.map((image, index) => (
                                    <button
                                        key={image.id}
                                        type="button"
                                        onClick={() => setActiveImage(index)}
                                        onMouseEnter={() => setActiveImage(index)}
                                        className={`h-16 w-16 shrink-0 overflow-hidden rounded-xl border-2 transition ${
                                            index === activeImage
                                                ? 'border-brand-600 ring-2 ring-brand-600/20'
                                                : 'border-gray-200 opacity-70 hover:opacity-100'
                                        }`}
                                        aria-label={`Image ${index + 1}`}
                                    >
                                        <img src={image.url} alt="" className="h-full w-full object-cover" />
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* ── Info column ── */}
                    <div className="flex flex-col">
                        <Link
                            href={route('catalog.index', { category: product.category.slug })}
                            className="w-fit rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-100"
                        >
                            {product.category.name}
                        </Link>

                        <h1 className="mt-3 text-2xl font-extrabold leading-tight tracking-tight text-gray-900 sm:text-3xl">
                            {product.name}
                        </h1>

                        <div className="mt-2 flex flex-wrap items-center gap-3">
                            {product.ratingAverage !== null ? (
                                <RatingStars average={product.ratingAverage} count={product.ratingCount} size="md" />
                            ) : (
                                <span className="text-sm text-gray-400">No reviews yet</span>
                            )}
                            <span className="text-gray-200">|</span>
                            <span className="inline-flex items-center gap-1.5 text-sm text-gray-500">
                                <Store className="h-4 w-4 text-gray-400" />
                                {t('Sold by')} <span className="font-semibold text-gray-800">{product.vendorName}</span>
                            </span>
                        </div>

                            {/* Price, next to the product rather than only in
                                the buy box. A shopper reading the title should
                                not have to look across the page to find what
                                it costs. */}
                            <div className="mt-5">
                                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <span className="text-3xl font-extrabold tracking-tight text-gray-900">
                                        {money(product.priceKobo)}
                                    </span>
                                    {hasDiscount && (
                                        <>
                                            <span className="text-lg text-gray-400 line-through">
                                                {money(product.compareAtPriceKobo as number)}
                                            </span>
                                            <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-bold text-red-600">
                                                −{savingsPercent}%
                                            </span>
                                        </>
                                    )}
                                </div>

                                {hasDiscount && (
                                    <p className="mt-1 text-sm font-semibold text-green-700">
                                        {t('You save')}{' '}
                                        {money((product.compareAtPriceKobo as number) - product.priceKobo)}
                                    </p>
                                )}

                                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                    <span
                                        className={
                                            inStock
                                                ? 'font-semibold text-green-700'
                                                : 'font-semibold text-red-600'
                                        }
                                    >
                                        {inStock ? t('In stock') : t('Out of stock')}
                                    </span>
                                    {lowStock && (
                                        <span className="text-amber-600">
                                            {t('Only')} {product.stockQuantity} {t('left')}
                                        </span>
                                    )}
                                    <span className="text-gray-400">
                                        {t('Pay at once or save small small')}
                                    </span>
                                </div>
                            </div>

                            {/* Trust strip — short, so it stays beside the
                                gallery. The long reading sits below. */}
                            <div className="mt-6 grid gap-3 border-t border-gray-100 pt-5 sm:grid-cols-2">
                                {[
                                    { icon: ShieldCheck, text: 'Paystack-secured payments' },
                                    { icon: Truck, text: 'FirstMaket delivery nationwide' },
                                    { icon: Store, text: 'Verified vendor' },
                                    { icon: Undo2, text: '7-day returns on eligible items' },
                                ].map((item) => (
                                    <div key={item.text} className="flex items-center gap-2.5 text-sm text-gray-600">
                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                            <item.icon className="h-4 w-4" />
                                        </span>
                                        {item.text}
                                    </div>
                                ))}
                            </div>
                        </div>
                        </div>

                        {/* ── Section jump bar ── */}
                        <nav className="scrollbar-none mt-8 flex gap-6 overflow-x-auto border-b border-gray-200 pb-3 text-sm">
                            {[
                                { href: '#description', label: t('Description') },
                                // Only offered when there is one to jump to.
                                ...(product.video ? [{ href: '#video', label: t('Video') }] : []),
                                { href: '#specifications', label: t('Specifications') },
                                { href: '#store', label: t('Store') },
                                { href: '#more-to-love', label: t('More to love') },
                            ].map((tab) => (
                                <a
                                    key={tab.href}
                                    href={tab.href}
                                    className="whitespace-nowrap font-semibold text-gray-600 transition-colors hover:text-brand-700"
                                >
                                    {tab.label}
                                </a>
                            ))}
                        </nav>

                        {/* ── Description ── */}
                        <section id="description" className="scroll-mt-24 pt-6">
                            <h2 className="text-lg font-extrabold text-gray-900">{t('Description')}</h2>
                            <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-gray-700">
                                {product.description}
                            </p>
                        </section>

                        {/* ── Video ──
                            embedUrl is built server-side from the extracted
                            video id, so what lands in this iframe can only ever
                            be a YouTube or Vimeo player — never the raw string
                            a vendor pasted. */}
                        {product.video && (
                            <section id="video" className="scroll-mt-24 pt-8">
                                <h2 className="text-lg font-extrabold text-gray-900">{t('Video')}</h2>
                                <div className="mt-3 max-w-2xl">
                                    <VideoPlayer video={product.video} productName={product.name} />
                                    <a
                                        href={product.video.watchUrl}
                                        target="_blank"
                                        rel="noopener noreferrer nofollow"
                                        className="mt-2 inline-block text-sm font-semibold text-brand-700 hover:underline"
                                    >
                                        {t('Watch on')} {product.video.providerName}
                                    </a>
                                </div>
                            </section>
                        )}

                        {/* ── Specifications ── */}
                        <section id="specifications" className="scroll-mt-24 pt-8">
                            <h2 className="text-lg font-extrabold text-gray-900">{t('Specifications')}</h2>
                            <dl className="mt-3 overflow-hidden rounded-xl border border-gray-100">
                                {[
                                    // Vendor answers to the fields staff defined
                                    // for this category come first — they are the
                                    // specifics a shopper is actually looking for.
                                    ...product.specifications,
                                    { label: 'Category', value: product.category.name },
                                    { label: t('Sold by'), value: product.vendorName },
                                    {
                                        label: 'Availability',
                                        value: inStock ? `${product.stockQuantity} in stock` : t('Out of stock'),
                                    },
                                ].map((row, i) => (
                                    <div
                                        key={row.label}
                                        className={`grid grid-cols-[minmax(0,140px)_minmax(0,1fr)] text-sm ${
                                            i % 2 === 0 ? 'bg-slate-50/70' : 'bg-white'
                                        }`}
                                    >
                                        <dt className="px-4 py-3 font-medium text-gray-500">{row.label}</dt>
                                        <dd className="px-4 py-3 text-gray-800">
                                            {/* A list field draws a real list. Run
                                                together into one paragraph, a set
                                                of key features is unreadable — which
                                                is exactly what it looked like before
                                                these types existed. */}
                                            {'items' in row && row.items && row.items.length > 0 ? (
                                                row.listStyle === 'numbered' ? (
                                                    <ol className="list-decimal space-y-1 pl-4 marker:text-gray-400">
                                                        {row.items.map((item) => (
                                                            <li key={item}>{item}</li>
                                                        ))}
                                                    </ol>
                                                ) : (
                                                    <ul className="list-disc space-y-1 pl-4 marker:text-gray-400">
                                                        {row.items.map((item) => (
                                                            <li key={item}>{item}</li>
                                                        ))}
                                                    </ul>
                                                )
                                            ) : (
                                                row.value
                                            )}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>

                        {/* ── Store ── */}
                        <section id="store" className="scroll-mt-24 pt-8">
                            <h2 className="text-lg font-extrabold text-gray-900">{t('Store')}</h2>
                            <div className="mt-3 flex items-center gap-3 rounded-xl border border-gray-100 bg-white p-4">
                                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-base font-extrabold text-white">
                                    {product.vendorName.slice(0, 2).toUpperCase()}
                                </span>
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-gray-900">{product.vendorName}</p>
                                    <p className="flex items-center gap-1 text-xs text-emerald-600">
                                        <ShieldCheck className="h-3.5 w-3.5" /> Verified vendor on FirstMaket
                                    </p>
                                </div>
                            </div>
                        </section>
                    </div>

                    {/* ── Buy box — pinned beside all of the above ── */}
                    <div className="lg:sticky lg:top-24 lg:self-start">
                        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                            {/* Price */}
                            <div className="rounded-xl border border-brand-yellow/50 bg-brand-yellow/10 px-4 py-3">
                                <p className="text-[11px] font-bold uppercase tracking-wide text-brand-700">
                                    FirstMaket price
                                </p>
                                <p className="mt-0.5 flex flex-wrap items-baseline gap-x-2.5">
                                    <span className="text-3xl font-extrabold tracking-tight text-brand-700">
                                        {money(product.priceKobo)}
                                    </span>
                                    {hasDiscount && (
                                        <>
                                            <s className="text-sm text-gray-400">
                                                {money(product.compareAtPriceKobo as number)}
                                            </s>
                                            <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-600">
                                                Save {savingsPercent}%
                                            </span>
                                        </>
                                    )}
                                </p>
                            </div>

                            {/* Stock */}
                            <p className="mt-3 flex items-center gap-2 text-sm">
                                {inStock ? (
                                    <>
                                        <span className="flex h-2 w-2 rounded-full bg-emerald-500" />
                                        <span className={lowStock ? 'font-semibold text-orange-600' : 'text-gray-600'}>
                                            {lowStock
                                                ? `Only ${product.stockQuantity} left — order soon`
                                                : t('In stock')}
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        <span className="flex h-2 w-2 rounded-full bg-gray-300" />
                                        <span className="text-gray-500">{t('Out of stock')}</span>
                                    </>
                                )}
                            </p>

                            {/* Quantity */}
                            {inStock && (
                                <div className="mt-4 flex items-center justify-between">
                                    <span className="text-sm font-semibold text-gray-900">{t('Quantity')}</span>
                                    <QuantityStepper
                                        value={quantity}
                                        max={maxQuantity}
                                        onChange={setQuantity}
                                    />
                                </div>
                            )}

                            {/* Amount summary — what this quantity costs,
                                itemised, before anything else in the cart. */}
                            {inStock && (
                                <dl className="mt-4 space-y-1.5 border-t border-dashed border-gray-200 pt-3 text-sm">
                                    <div className="flex items-center justify-between text-gray-500">
                                        <dt>
                                            {money(product.priceKobo)} × {quantity}
                                        </dt>
                                        <dd className="tabular-nums">{money(lineSubtotalKobo)}</dd>
                                    </div>
                                    {lineSavingKobo > 0 && (
                                        <div className="flex items-center justify-between text-emerald-600">
                                            <dt>{t('You save')}</dt>
                                            <dd className="tabular-nums">−{money(lineSavingKobo)}</dd>
                                        </div>
                                    )}
                                    <div className="flex items-center justify-between text-gray-500">
                                        <dt>Delivery</dt>
                                        <dd className="tabular-nums">
                                            {qualifiesFreeShipping ? (
                                                <span className="font-semibold text-emerald-600">Free</span>
                                            ) : (
                                                'Calculated at checkout'
                                            )}
                                        </dd>
                                    </div>
                                    <div className="flex items-baseline justify-between border-t border-gray-100 pt-2">
                                        <dt className="text-sm font-bold text-gray-900">{t('Order total')}</dt>
                                        <dd className="text-lg font-extrabold tabular-nums text-gray-900">
                                            {money(lineSubtotalKobo)}
                                        </dd>
                                    </div>
                                </dl>
                            )}

                            {/* Buy now goes straight to checkout with only
                                this item and never touches the cart, so a
                                shopper mid-basket does not lose what they had.
                                Add to cart stays the calmer second action. */}
                            {inStock && (
                                <>
                                    <Link
                                        href={route('cart.checkout', {
                                            buy_now: product.uuid,
                                            qty: quantity,
                                        })}
                                        className="mt-4 flex w-full items-center justify-center gap-2 rounded-full bg-brand-600 py-3.5 text-sm font-bold text-white transition hover:bg-brand-700 hover:shadow-lg hover:shadow-brand-600/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-yellow active:scale-[0.98]"
                                    >
                                        <Zap className="h-4 w-4" />
                                        {t('Buy now')}
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            addToCart(product.uuid, { quantity, productName: product.name })
                                        }
                                        disabled={adding}
                                        className="mt-2.5 flex w-full items-center justify-center gap-2 rounded-full border border-gray-300 bg-white py-3.5 text-sm font-bold text-gray-900 transition hover:border-brand-600 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/30 active:scale-[0.98] disabled:opacity-60"
                                    >
                                        <ShoppingBag className="h-4 w-4" />
                                        {adding ? 'Adding…' : t('Add to cart')}
                                    </button>
                                </>
                            )}

                            {cart.quantityOfThisProduct > 0 && (
                                <p className="mt-2.5 rounded-lg bg-emerald-50 px-3 py-2 text-center text-xs font-medium text-emerald-700">
                                    {cart.quantityOfThisProduct} already in your cart
                                </p>
                            )}

                            <p className="mt-3 text-center text-xs leading-relaxed text-gray-400">
                                Pay at once or save small small — choose at checkout.
                            </p>

                            {/* Tap-through terms: shipping, returns, security */}
                            <BuyBoxPolicies freeShippingThresholdKobo={freeShippingThresholdKobo} />
                        </div>

                        {/* Vendor mini-card */}
                        <div className="mt-3 flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-sm font-extrabold text-white">
                                {product.vendorName.slice(0, 2).toUpperCase()}
                            </span>
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-gray-900">{product.vendorName}</p>
                                <p className="flex items-center gap-1 text-xs text-emerald-600">
                                    <ShieldCheck className="h-3.5 w-3.5" /> Verified vendor
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ── Related ── */}
                {relatedProducts.length > 0 && (
                    <Reveal className="mt-10">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-lg font-extrabold text-gray-900">
                                <Zap className="h-5 w-5 text-brand-600" /> More in {product.category.name}
                            </h2>
                            <Link
                                href={route('catalog.index', { category: product.category.slug })}
                                className="text-sm font-semibold text-brand-600 hover:underline"
                            >
                                See all →
                            </Link>
                        </div>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                            {relatedProducts.map((related) => (
                                <ProductCard key={related.uuid} product={related} onQuickView={setQuickView} />
                            ))}
                        </div>
                    </Reveal>
                )}

                {/* ── More to love: deliberately outside this category, so the
                    page ends on discovery rather than more of the same. ── */}
                {moreToLove.length > 0 && (
                    <Reveal className="mt-12" id="more-to-love">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-lg font-extrabold text-gray-900">
                                <Heart className="h-5 w-5 text-red-500" /> {t('More to love')}
                            </h2>
                            <Link
                                href={route('catalog.index')}
                                className="text-sm font-semibold text-brand-600 hover:underline"
                            >
                                Browse everything →
                            </Link>
                        </div>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                            {moreToLove.map((item) => (
                                <ProductCard key={item.uuid} product={item} onQuickView={setQuickView} />
                            ))}
                        </div>
                    </Reveal>
                )}
            </div>
        </PublicLayout>
    );
}
