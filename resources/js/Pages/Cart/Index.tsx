import { useAuthModal } from '@/Components/domain/auth/auth-modal-context';
import PaymentMarks from '@/Components/ui/PaymentMarks';
import QuantityStepper from '@/Components/ui/QuantityStepper';
import PublicLayout from '@/Layouts/PublicLayout';
import { PageProps } from '@/Types';
import { productLinkProps } from '@/Utils/links';
import { useAddToCart } from '@/Hooks/useAddToCart';
import { useMoney } from '@/Hooks/useI18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, ShieldCheck, ShoppingBag, Store, Tag, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

interface CartItemRow {
    /** null for guests; unused now that lines are addressed by product uuid. */
    cartItemId: number | null;
    productUuid: string;
    productName: string;
    productSlug: string;
    productImage: string | null;
    categoryName: string;
    vendorName: string;
    priceKobo: number;
    compareAtPriceKobo: number | null;
    quantity: number;
    lineTotalKobo: number;
    stockQuantity: number;
}

interface Summary {
    itemsTotalKobo: number;
    discountKobo: number;
    subtotalKobo: number;
    shippingKobo: number;
    totalKobo: number;
    itemCount: number;
    freeShippingThresholdKobo: number;
}

interface Recommendation {
    uuid: string;
    name: string;
    slug: string;
    image: string | null;
    priceKobo: number;
    categoryName: string | null;
    vendorName: string | null;
}

interface Props extends PageProps {
    items: CartItemRow[];
    /** True while no delivery address is known, so the fee may change. */
    deliveryIsEstimate: boolean;
    summary: Summary;
    recommendations: Recommendation[];
}

/**
 * Marketplace cart (the AliExpress/Temu anatomy the brief asked for): a
 * select-all header with bulk delete, one flat list of compact lines each
 * with their own checkbox, quantity stepper and per-unit savings, and a
 * sticky summary rail reconciling items total → discount → subtotal →
 * delivery → total.
 *
 * Lines are deliberately not grouped by vendor: who sells an item is a
 * property of the line, shown next to its category, not a reason to break
 * one cart into several boxes the shopper has to read across.
 *
 * Selection drives the summary, so the totals always describe exactly what
 * the Checkout button is about to buy. Guests get the full page; only the
 * Checkout button gates on sign-in.
 */
export default function CartIndex() {
    const { money } = useMoney();
    const { items, summary, recommendations, deliveryIsEstimate, auth } = usePage<Props>().props;
    const openAuth = useAuthModal();

    const [pendingUuid, setPendingUuid] = useState<string | null>(null);
    // Everything starts selected, like every marketplace cart.
    const [deselected, setDeselected] = useState<string[]>([]);

    const isSelected = (uuid: string) => !deselected.includes(uuid);
    const selectedItems = items.filter((item) => isSelected(item.productUuid));
    const allSelected = deselected.length === 0 && items.length > 0;

    // Recomputed client-side so ticking a box updates instantly; the server
    // figures in CartSummary remain the source of truth for a full cart.
    const selectedTotals = useMemo(() => {
        const subtotal = selectedItems.reduce((sum, item) => sum + item.lineTotalKobo, 0);
        const itemsTotal = selectedItems.reduce(
            (sum, item) => sum + Math.max(item.compareAtPriceKobo ?? 0, item.priceKobo) * item.quantity,
            0,
        );
        // Mirrors DeliveryPricing::feeKobo. A threshold of 0 means "never
        // free" — comparing against it directly made `subtotal >= 0` true for
        // every cart, so the page showed free delivery on orders the server
        // was about to charge for.
        const earnsFreeDelivery =
            summary.freeShippingThresholdKobo > 0 && subtotal >= summary.freeShippingThresholdKobo;
        const shipping = subtotal === 0 || earnsFreeDelivery ? 0 : summary.shippingKobo || 0;

        return {
            itemsTotal,
            discount: itemsTotal - subtotal,
            subtotal,
            shipping,
            total: subtotal + shipping,
            count: selectedItems.reduce((sum, item) => sum + item.quantity, 0),
        };
    }, [selectedItems, summary.freeShippingThresholdKobo, summary.shippingKobo]);

    const toggleItem = (uuid: string) =>
        setDeselected((current) =>
            current.includes(uuid) ? current.filter((id) => id !== uuid) : [...current, uuid],
        );

    const toggleAll = () =>
        setDeselected(allSelected ? items.map((item) => item.productUuid) : []);

    const setQuantity = (item: CartItemRow, quantity: number) => {
        if (quantity < 1 || quantity > item.stockQuantity) return;

        setPendingUuid(item.productUuid);
        router.patch(
            route('cart.items.update', item.productUuid),
            { quantity },
            { preserveScroll: true, onFinish: () => setPendingUuid(null) },
        );
    };

    const removeItem = (item: CartItemRow) => {
        setPendingUuid(item.productUuid);
        router.delete(route('cart.items.destroy', item.productUuid), {
            preserveScroll: true,
            onFinish: () => setPendingUuid(null),
        });
    };

    const removeSelected = () => {
        // Sequential, so each response reflects the previous deletion.
        selectedItems.forEach((item) =>
            router.delete(route('cart.items.destroy', item.productUuid), { preserveScroll: true }),
        );
    };

    const checkout = () => {
        // No route from cart to checkout without an account — but the click
        // was a request for checkout, so hand that destination to the modal
        // and let signing in finish the job rather than dumping them back
        // here to click again.
        if (!auth.user) {
            openAuth('/cart/checkout');

            return;
        }

        router.get(route('cart.checkout'));
    };

    return (
        <PublicLayout>
            <Head title="My Cart" />

            <div className="mx-auto max-w-7xl px-3 py-6 sm:px-4">
                {items.length === 0 ? (
                    <EmptyCart />
                ) : (
                    <>
                    <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                        {/* ── Lines ── */}
                        <div className="space-y-4">
                            <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
                                <h1 className="text-xl font-extrabold tracking-tight text-gray-900">
                                    Cart ({summary.itemCount})
                                </h1>

                                <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                                    <label className="flex cursor-pointer items-center gap-2 font-medium text-gray-700">
                                        <Checkbox checked={allSelected} onChange={toggleAll} />
                                        Select all items
                                    </label>
                                    <span className="hidden h-4 w-px bg-gray-200 sm:block" />
                                    <button
                                        type="button"
                                        onClick={removeSelected}
                                        disabled={selectedItems.length === 0}
                                        className="font-semibold text-brand-600 underline underline-offset-2 transition hover:text-brand-700 disabled:cursor-not-allowed disabled:text-gray-300 disabled:no-underline"
                                    >
                                        Delete selected items
                                    </button>
                                </div>

                                {summary.subtotalKobo < summary.freeShippingThresholdKobo && (
                                    <p className="mt-4 rounded-xl bg-gradient-to-r from-brand-600 to-brand-500 px-4 py-2.5 text-sm font-semibold text-white">
                                        Add{' '}
                                        {money(
                                            summary.freeShippingThresholdKobo - summary.subtotalKobo,
                                        )}{' '}
                                        more for free delivery 🚚
                                    </p>
                                )}
                            </div>

                            {/* One flat list — the vendor is a property of each
                                line, not a heading to split the cart under. */}
                            <div className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                                <ul className="divide-y divide-gray-100">
                                    {items.map((item) => (
                                        <CartLine
                                            key={item.productUuid}
                                            item={item}
                                            selected={isSelected(item.productUuid)}
                                            pending={pendingUuid === item.productUuid}
                                            onToggle={() => toggleItem(item.productUuid)}
                                            onQuantity={(q) => setQuantity(item, q)}
                                            onRemove={() => removeItem(item)}
                                        />
                                    ))}
                                </ul>
                            </div>

                        </div>

                        {/* ── Summary rail ── */}
                        <div className="space-y-4 lg:sticky lg:top-24">
                            <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
                                <h2 className="text-lg font-extrabold text-gray-900">Summary</h2>

                                {selectedItems.length > 0 && (
                                    <div className="mt-4 flex -space-x-2">
                                        {selectedItems.slice(0, 5).map((item) =>
                                            item.productImage ? (
                                                <img loading="lazy" decoding="async"
                                                    key={item.productUuid}
                                                    src={item.productImage}
                                                    alt=""
                                                    className="h-11 w-11 rounded-lg border-2 border-white bg-gray-50 object-cover shadow-sm"
                                                />
                                            ) : null,
                                        )}
                                        {selectedItems.length > 5 && (
                                            <span className="flex h-11 w-11 items-center justify-center rounded-lg border-2 border-white bg-gray-100 text-xs font-bold text-gray-500 shadow-sm">
                                                +{selectedItems.length - 5}
                                            </span>
                                        )}
                                    </div>
                                )}

                                <dl className="mt-4 space-y-2.5 text-sm">
                                    <Row label="Items total">
                                        {selectedTotals.discount > 0 ? (
                                            <s className="text-gray-400">
                                                {money(selectedTotals.itemsTotal)}
                                            </s>
                                        ) : (
                                            money(selectedTotals.itemsTotal)
                                        )}
                                    </Row>
                                    {selectedTotals.discount > 0 && (
                                        <Row label="Items discount" tone="discount">
                                            −{money(selectedTotals.discount)}
                                        </Row>
                                    )}
                                    <Row label="Subtotal" strong>
                                        {money(selectedTotals.subtotal)}
                                    </Row>
                                    <Row label={deliveryIsEstimate ? 'Delivery (estimated)' : 'Delivery'}>
                                        {selectedTotals.shipping === 0 ? (
                                            <span className="font-semibold text-emerald-600">Free</span>
                                        ) : (
                                            money(selectedTotals.shipping)
                                        )}
                                    </Row>
                                </dl>

                                {/* Said plainly rather than quietly revised one
                                    page later: delivery is priced per state,
                                    and we do not know the state yet. */}
                                {deliveryIsEstimate && selectedTotals.shipping > 0 && (
                                    <p className="mt-2 text-xs text-gray-400">
                                        Final delivery is confirmed at checkout, once you add your address.
                                    </p>
                                )}

                                {/* Wraps rather than clips: a seven-figure naira
                                    total beside its label is wider than a small
                                    phone, and the amount is the one thing on
                                    this card that must never be cut off. */}
                                <div className="mt-4 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-t border-gray-100 pt-4">
                                    <span className="text-base font-bold text-gray-900">Estimated total</span>
                                    <span className="text-xl font-extrabold tabular-nums tracking-tight text-gray-900 sm:text-2xl">
                                        {money(selectedTotals.total)}
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    onClick={checkout}
                                    disabled={selectedItems.length === 0}
                                    className="mt-4 w-full rounded-full bg-brand-600 py-3.5 text-sm font-bold text-white transition hover:bg-brand-700 hover:shadow-lg hover:shadow-brand-600/25 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400 disabled:shadow-none"
                                >
                                    Checkout ({selectedTotals.count})
                                </button>
                               
                            </div>

                            <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
                                <h3 className="text-sm font-bold text-gray-900">Pay with</h3>
                                <PaymentMarks />

                                <h3 className="mt-5 text-sm font-bold text-gray-900">Buyer protection</h3>
                                <p className="mt-2 flex gap-2 text-xs leading-relaxed text-gray-500">
                                    <ShieldCheck className="h-4 w-4 shrink-0 text-emerald-600" />
                                    Get a full refund if the item is not as described or not delivered. FirstMaket
                                    holds payment until you confirm delivery.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Full width, under both columns — a recommendation rail
                        squeezed into the lines column reads as an afterthought. */}
                    {recommendations.length > 0 && (
                        <div className="mt-5">
                            <YouMayAlsoLike products={recommendations} />
                        </div>
                    )}
                    </>
                )}
            </div>
        </PublicLayout>
    );
}

function CartLine({
    item,
    selected,
    pending,
    onToggle,
    onQuantity,
    onRemove,
}: {
    item: CartItemRow;
    selected: boolean;
    pending: boolean;
    onToggle: () => void;
    onQuantity: (quantity: number) => void;
    onRemove: () => void;
}) {
    const { money } = useMoney();
    const hasDiscount = item.compareAtPriceKobo !== null && item.compareAtPriceKobo > item.priceKobo;
    const savingsPercent = hasDiscount
        ? Math.round((1 - item.priceKobo / (item.compareAtPriceKobo as number)) * 100)
        : 0;
    const lowStock = item.stockQuantity <= 5;

    return (
        /*
         * Wraps on phones: the stepper and the money are a fixed ~110px that
         * cannot shrink, so keeping them on the same line as the thumbnail
         * pushed the row past the viewport and put a horizontal scrollbar
         * under the whole page. Below `sm` they drop to their own full-width
         * line; from `sm` up nothing wraps and the original rail returns.
         */
        <li
            className={`flex flex-wrap gap-x-3 gap-y-3 px-3 py-4 transition-opacity sm:flex-nowrap sm:gap-x-4 sm:px-4 ${pending ? 'opacity-50' : ''}`}
        >
            <span className="flex items-center">
                <Checkbox checked={selected} onChange={onToggle} label={`Select ${item.productName}`} />
            </span>

            <a
                {...productLinkProps(item.productSlug)}
                className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50 sm:h-24 sm:w-24"
            >
                {item.productImage ? (
                    <img loading="lazy" decoding="async" src={item.productImage} alt="" className="h-full w-full object-cover" />
                ) : (
                    <ShoppingBag className="h-7 w-7 text-gray-300" />
                )}
            </a>

            {/* Details left, controls and money right — the anatomy every
                large marketplace settles on, so the prices form one
                scannable column instead of hiding at the end of each line. */}
            <div className="flex min-w-0 flex-1 flex-col">
                <a
                    {...productLinkProps(item.productSlug)}
                    className="line-clamp-2 text-sm font-medium leading-snug text-gray-900 hover:text-brand-600"
                >
                    {item.productName}
                </a>

                {/* Category, seller and stock as chips: three different facts
                    that used to run together as one grey sentence. Colour
                    carries the hierarchy — the seller is brand-tinted because
                    it is the one people actually look for, stock turns amber
                    only when it is running out. */}
                <div className="mt-1.5 flex min-w-0 flex-wrap items-center gap-1.5">
                    <span className="inline-flex max-w-full items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                        <Tag className="h-3 w-3 shrink-0" aria-hidden="true" />
                        <span className="truncate">{item.categoryName}</span>
                    </span>

                    <span className="inline-flex max-w-full items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700 ring-1 ring-inset ring-brand-100">
                        <Store className="h-3 w-3 shrink-0" aria-hidden="true" />
                        <span className="truncate">{item.vendorName}</span>
                    </span>

                    {lowStock ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700 ring-1 ring-inset ring-amber-200">
                            <AlertTriangle className="h-3 w-3 shrink-0" aria-hidden="true" />
                            Only {item.stockQuantity} left
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                            <Check className="h-3 w-3 shrink-0" aria-hidden="true" />
                            {item.stockQuantity} in stock
                        </span>
                    )}
                </div>

                <button
                    type="button"
                    onClick={onRemove}
                    disabled={pending}
                    className="mt-auto inline-flex items-center gap-1.5 self-start rounded-full border border-gray-200 px-2.5 py-1 text-[11px] font-bold text-gray-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 active:scale-95 disabled:opacity-50"
                >
                    <Trash2 className="h-3 w-3" aria-hidden="true" />
                    Remove
                </button>
            </div>

            <div className="flex w-full items-center justify-between gap-3 sm:w-auto sm:shrink-0 sm:flex-col sm:items-end sm:gap-2">
                <QuantityStepper
                    value={item.quantity}
                    max={item.stockQuantity}
                    disabled={pending}
                    onChange={onQuantity}
                    label={`Quantity of ${item.productName}`}
                />

                <div className="text-right">
                    <span className="block text-base font-bold tracking-tight text-gray-900">
                        {money(item.lineTotalKobo)}
                    </span>
                    {item.quantity > 1 && (
                        <span className="mt-0.5 block text-[11px] text-gray-400">
                            {money(item.priceKobo)} each
                        </span>
                    )}
                    {hasDiscount && (
                        <span className="mt-1 flex items-center justify-end gap-1.5">
                            <s className="text-[11px] text-gray-400">
                                {money((item.compareAtPriceKobo as number) * item.quantity)}
                            </s>
                            <span className="rounded bg-red-50 px-1.5 py-0.5 text-[11px] font-bold text-red-600">
                                −{savingsPercent}%
                            </span>
                        </span>
                    )}
                </div>
            </div>
        </li>
    );
}

/**
 * Suggestions drawn from the categories already in the cart. Uses the same
 * card anatomy as the catalogue grid so it reads as more of the marketplace
 * rather than an advert bolted onto the cart.
 */
function YouMayAlsoLike({ products }: { products: Recommendation[] }) {
    const { money } = useMoney();
    const { addToCart, adding } = useAddToCart();

    return (
        <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
            <h2 className="text-base font-extrabold tracking-tight text-gray-900">You may also like</h2>
            <p className="mt-0.5 text-xs text-gray-400">Picked from the categories in your cart.</p>

            <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {products.map((product) => (
                    <div key={product.uuid} className="group flex flex-col">
                        <a
                            {...productLinkProps(product.slug)}
                            className="flex aspect-square w-full items-center justify-center overflow-hidden rounded-xl bg-gray-50"
                        >
                            {product.image ? (
                                <img loading="lazy" decoding="async"
                                    src={product.image}
                                    alt=""
                                    className="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                                />
                            ) : (
                                <ShoppingBag className="h-6 w-6 text-gray-300" />
                            )}
                        </a>

                        {/* Two fixed lines for the name, so a title that wraps
                            does not shove this card's price and button out of
                            line with its neighbours. */}
                        <a
                            {...productLinkProps(product.slug)}
                            className="mt-2 line-clamp-2 min-h-[2rem] text-xs font-medium leading-snug text-gray-900 hover:text-brand-600"
                        >
                            {product.name}
                        </a>
                        <span className="mt-0.5 min-h-[0.875rem] truncate text-[11px] text-gray-400">
                            {product.vendorName}
                        </span>
                        <span className="mt-1 text-sm font-bold tabular-nums text-brand-700">
                            {money(product.priceKobo)}
                        </span>

                        <button
                            type="button"
                            onClick={() => addToCart(product.uuid, { productName: product.name })}
                            disabled={adding}
                            className="mt-1.5 rounded-full border border-gray-200 py-1.5 text-[11px] font-bold text-gray-600 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 active:scale-95 disabled:opacity-50"
                        >
                            Add to cart
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}

function Row({
    label,
    children,
    tone,
    strong,
}: {
    label: string;
    children: React.ReactNode;
    tone?: 'discount';
    strong?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className={strong ? 'font-semibold text-gray-900' : 'text-gray-500'}>{label}</dt>
            <dd
                className={`shrink-0 text-right tabular-nums ${
                    tone === 'discount'
                        ? 'font-semibold text-red-600'
                        : strong
                          ? 'font-bold text-gray-900'
                          : 'text-gray-700'
                }`}
            >
                {children}
            </dd>
        </div>
    );
}

function Checkbox({
    checked,
    onChange,
    label,
}: {
    checked: boolean;
    onChange: () => void;
    label?: string;
}) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            aria-label={label}
            onClick={onChange}
            className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition ${
                checked
                    ? 'border-brand-600 bg-brand-600 text-white'
                    : 'border-gray-300 bg-white hover:border-brand-400'
            }`}
        >
            {checked && (
                <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" strokeWidth={4} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            )}
        </button>
    );
}

function EmptyCart() {
    return (
        <div className="mx-auto flex max-w-md flex-col items-center rounded-2xl border border-gray-100 bg-white px-6 py-16 text-center shadow-sm">
            <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                <ShoppingBag className="h-8 w-8" />
            </span>
            <h1 className="mt-5 text-lg font-extrabold text-gray-900">Your cart is empty</h1>
            <p className="mt-1.5 text-sm text-gray-500">
                Browse the marketplace and add something you love — you don't need an account to start.
            </p>
            <Link
                href={route('catalog.index')}
                className="mt-5 rounded-full bg-brand-600 px-6 py-3 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
            >
                Start shopping
            </Link>
        </div>
    );
}
