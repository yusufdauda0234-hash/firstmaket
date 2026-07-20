import { useAuthModal } from '@/Components/domain/auth/auth-modal-context';
import { CartGlyph, ProductCard, RatingStars } from '@/Components/domain/catalog/ProductCard';
import QuickViewModal from '@/Components/domain/catalog/QuickViewModal';
import Reveal from '@/Components/ui/Reveal';
import PublicLayout from '@/Layouts/PublicLayout';
import { Category, PageProps, ProductSummary } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, PiggyBank, ShieldCheck, Store, Truck, Undo2, Zap } from 'lucide-react';
import { ReactNode, useEffect, useState } from 'react';

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
    };
    relatedProducts: ProductSummary[];
    categories: Category[];
}

function AuthGateButton({
    href,
    className,
    children,
}: {
    href: string;
    className: string;
    children: ReactNode;
}) {
    const openAuth = useAuthModal();
    const { auth } = usePage<PageProps>().props;

    if (auth.user) {
        return (
            <Link href={href} className={className}>
                {children}
            </Link>
        );
    }

    return (
        <button type="button" onClick={openAuth} className={className}>
            {children}
        </button>
    );
}

export default function ProductShow({ product, relatedProducts, categories }: ProductShowProps) {
    const [activeImage, setActiveImage] = useState(0);
    const [quantity, setQuantity] = useState(1);
    const [zoom, setZoom] = useState<{ x: number; y: number } | null>(null);
    const [quickView, setQuickView] = useState<ProductSummary | null>(null);

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

    return (
        <PublicLayout categories={categories}>
            <Head>
                <title>{`${product.name} — FirstMarket`}</title>
                <meta
                    name="description"
                    content={`Buy ${product.name} on FirstMarket — pay at once or save small small at a locked price. ${formatNairaFromKobo(product.priceKobo)}.`}
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

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)_320px]">
                    {/* ── Gallery ── */}
                    <div className="lg:sticky lg:top-24 lg:self-start">
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
                                Sold by <span className="font-semibold text-gray-800">{product.vendorName}</span>
                            </span>
                        </div>

                        {/* Description */}
                        <div className="mt-6 border-t border-gray-100 pt-5">
                            <h2 className="text-sm font-bold uppercase tracking-wide text-gray-400">Description</h2>
                            <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-gray-700">
                                {product.description}
                            </p>
                        </div>

                        {/* Specs / trust grid */}
                        <div className="mt-6 grid gap-3 border-t border-gray-100 pt-5 sm:grid-cols-2">
                            {[
                                { icon: ShieldCheck, text: 'Paystack-secured payments' },
                                { icon: Truck, text: 'FirstMarket delivery nationwide' },
                                { icon: Store, text: 'Verified vendor' },
                                { icon: Undo2, text: '30-day returns on eligible items' },
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

                    {/* ── Buy box (sticky) ── */}
                    <div className="lg:sticky lg:top-24 lg:self-start">
                        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                            {/* Price */}
                            <div className="rounded-xl border border-brand-yellow/50 bg-brand-yellow/10 px-4 py-3">
                                <p className="text-[11px] font-bold uppercase tracking-wide text-brand-700">
                                    FirstMarket price
                                </p>
                                <p className="mt-0.5 flex flex-wrap items-baseline gap-x-2.5">
                                    <span className="text-3xl font-extrabold tracking-tight text-brand-700">
                                        {formatNairaFromKobo(product.priceKobo)}
                                    </span>
                                    {hasDiscount && (
                                        <>
                                            <s className="text-sm text-gray-400">
                                                {formatNairaFromKobo(product.compareAtPriceKobo as number)}
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
                                                : 'In stock'}
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        <span className="flex h-2 w-2 rounded-full bg-gray-300" />
                                        <span className="text-gray-500">Out of stock</span>
                                    </>
                                )}
                            </p>

                            {/* Quantity */}
                            {inStock && (
                                <div className="mt-4 flex items-center justify-between">
                                    <span className="text-sm font-semibold text-gray-900">Quantity</span>
                                    <div className="flex items-center gap-1 rounded-full border border-gray-200 px-1.5 py-1">
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
                                            className="w-10 border-0 bg-transparent p-0 text-center text-sm font-semibold text-gray-900 [appearance:textfield] focus:outline-none focus:ring-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
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

                            {/* Actions */}
                            <div className="mt-4 space-y-2.5">
                                <AuthGateButton
                                    href={route('checkout.pay-at-once', product.slug)}
                                    className="flex w-full items-center justify-center gap-2 rounded-full bg-brand-600 py-3 text-sm font-bold text-white transition hover:bg-brand-700 hover:shadow-lg hover:shadow-brand-600/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-yellow active:scale-[0.98]"
                                >
                                    <CartGlyph /> Pay At Once — get it delivered
                                </AuthGateButton>
                                <AuthGateButton
                                    href={route('savings.plans.start', product.slug)}
                                    className="flex w-full items-center justify-center gap-2 rounded-full bg-brand-yellow py-3 text-sm font-bold text-brand-900 transition hover:bg-yellow-300 hover:shadow-lg active:scale-[0.98]"
                                >
                                    <PiggyBank className="h-4 w-4" /> Save Small Small — lock this price
                                </AuthGateButton>
                            </div>
                            <p className="mt-3 text-center text-xs leading-relaxed text-gray-400">
                                Price locked the day you start. No loans, no cash withdrawal.
                            </p>
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
            </div>
        </PublicLayout>
    );
}
