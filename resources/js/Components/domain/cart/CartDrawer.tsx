import { useAuthModal } from '@/Components/domain/auth/auth-modal-context';
import { PageProps } from '@/Types';
import { productLinkProps } from '@/Utils/links';
import { useMoney } from '@/Hooks/useI18n';
import { Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ShoppingBag, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface CartDrawerLine {
    productUuid: string;
    productName: string;
    productSlug: string;
    productImage: string | null;
    quantity: number;
    lineTotalKobo: number;
}

export interface CartDrawerData {
    itemCount: number;
    subtotalKobo: number;
    shippingKobo: number;
    totalKobo: number;
    quantityOfThisProduct: number;
    lines: CartDrawerLine[];
}

/**
 * The cart as a right-edge drawer rather than a card in the page flow: a
 * pull tab pinned to the viewport edge, and a panel that slides in from the
 * right and back out to the right on close.
 *
 * The panel stays mounted and is moved with translate-x so both directions
 * animate — unmounting on close would make it vanish instead of sliding
 * away. It is inert (pointer-events-none, aria-hidden, tab-trapped out)
 * whenever it is off-screen.
 */
export default function CartDrawer({ cart }: { cart: CartDrawerData }) {
    const { money } = useMoney();
    const { auth } = usePage<PageProps>().props;
    const openAuth = useAuthModal();
    const [open, setOpen] = useState(false);
    const [pendingUuid, setPendingUuid] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;

        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') setOpen(false);
        }

        document.addEventListener('keydown', onKey);

        // Restore whatever was set before — the quick-view and auth modals
        // lock scrolling too, and blindly clearing would unlock theirs.
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = previousOverflow;
        };
    }, [open]);

    const removeLine = (line: CartDrawerLine) => {
        setPendingUuid(line.productUuid);
        router.delete(route('cart.items.destroy', line.productUuid), {
            preserveScroll: true,
            onFinish: () => setPendingUuid(null),
        });
    };

    const checkout = () => {
        // Same gate as the cart page: no route to checkout without an account.
        if (!auth.user) {
            setOpen(false);
            openAuth('/cart/checkout');

            return;
        }

        router.get(route('cart.checkout'));
    };

    return (
        <>
            {/* Pull tab — pinned to the right edge, vertically centred */}
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={`Open cart, ${cart.itemCount} item${cart.itemCount === 1 ? '' : 's'}`}
                aria-expanded={open}
                className={`fixed right-0 top-1/2 z-40 flex -translate-y-1/2 items-center gap-1.5 rounded-l-2xl bg-brand-600 py-4 pl-3 pr-2.5 text-white shadow-lg shadow-brand-900/20 transition hover:bg-brand-700 hover:pr-4 ${
                    open ? 'pointer-events-none translate-x-full opacity-0' : ''
                }`}
            >
                <ChevronLeft className="h-4 w-4" />
                <span className="relative">
                    <ShoppingBag className="h-5 w-5" />
                    {cart.itemCount > 0 && (
                        <span className="absolute -right-2.5 -top-2 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-yellow px-1 text-[10px] font-bold text-brand-900">
                            {cart.itemCount > 99 ? '99+' : cart.itemCount}
                        </span>
                    )}
                </span>
            </button>

            {/* Backdrop */}
            <div
                onClick={() => setOpen(false)}
                aria-hidden="true"
                className={`fixed inset-0 z-40 bg-slate-900/50 transition-opacity duration-300 ${
                    open ? 'opacity-100' : 'pointer-events-none opacity-0'
                }`}
            />

            {/* Panel */}
            <aside
                role="dialog"
                aria-modal="true"
                aria-label="Your cart"
                aria-hidden={!open}
                // `invisible` rather than the (not-yet-typed) inert attribute:
                // visibility also takes the panel's buttons out of the tab
                // order, and because it transitions discretely it only kicks
                // in once the slide-out has finished.
                className={`fixed inset-y-0 right-0 z-50 flex w-full max-w-sm flex-col bg-white shadow-2xl transition-[transform,visibility] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] ${
                    open ? 'visible translate-x-0' : 'invisible translate-x-full'
                }`}
            >
                <header className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h2 className="flex items-center gap-2 text-base font-extrabold text-gray-900">
                        <ShoppingBag className="h-5 w-5 text-brand-600" />
                        Your cart
                        {cart.itemCount > 0 && (
                            <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-bold text-brand-700">
                                {cart.itemCount}
                            </span>
                        )}
                    </h2>
                    <button
                        type="button"
                        onClick={() => setOpen(false)}
                        aria-label="Close cart"
                        className="rounded-full p-1.5 text-gray-400 transition hover:bg-slate-100 hover:text-gray-700"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </header>

                {cart.lines.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center px-6 text-center">
                        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                            <ShoppingBag className="h-7 w-7" />
                        </span>
                        <p className="mt-4 text-sm font-semibold text-gray-900">Your cart is empty</p>
                        <p className="mt-1 text-sm text-gray-500">
                            Add this item to get started — no account needed.
                        </p>
                    </div>
                ) : (
                    <ul className="flex-1 divide-y divide-gray-100 overflow-y-auto px-5">
                        {cart.lines.map((line) => (
                            <li
                                key={line.productUuid}
                                className={`flex gap-3 py-3.5 transition-opacity ${
                                    pendingUuid === line.productUuid ? 'opacity-50' : ''
                                }`}
                            >
                                <a
                                    {...productLinkProps(line.productSlug)}
                                    className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50"
                                >
                                    {line.productImage ? (
                                        <img src={line.productImage} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                        <ShoppingBag className="h-5 w-5 text-gray-300" />
                                    )}
                                </a>
                                <div className="min-w-0 flex-1">
                                    <a
                                        {...productLinkProps(line.productSlug)}
                                        className="line-clamp-2 text-sm font-medium leading-snug text-gray-900 hover:text-brand-600"
                                    >
                                        {line.productName}
                                    </a>
                                    <p className="mt-1 text-xs text-gray-400">Qty {line.quantity}</p>
                                </div>
                                <div className="flex flex-col items-end justify-between">
                                    <span className="text-sm font-bold tabular-nums text-gray-900">
                                        {money(line.lineTotalKobo)}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => removeLine(line)}
                                        disabled={pendingUuid === line.productUuid}
                                        aria-label={`Remove ${line.productName}`}
                                        className="rounded-lg p-1 text-gray-300 transition hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {cart.lines.length > 0 && (
                    <footer className="border-t border-gray-100 px-5 py-4">
                        <dl className="space-y-1.5 text-sm">
                            <div className="flex justify-between text-gray-500">
                                <dt>Subtotal</dt>
                                <dd className="tabular-nums">{money(cart.subtotalKobo)}</dd>
                            </div>
                            <div className="flex justify-between text-gray-500">
                                <dt>Delivery</dt>
                                <dd className="tabular-nums">
                                    {cart.shippingKobo === 0 ? (
                                        <span className="font-semibold text-emerald-600">Free</span>
                                    ) : (
                                        money(cart.shippingKobo)
                                    )}
                                </dd>
                            </div>
                            <div className="flex items-baseline justify-between border-t border-gray-100 pt-2">
                                <dt className="font-bold text-gray-900">Total</dt>
                                <dd className="text-lg font-extrabold tabular-nums text-gray-900">
                                    {money(cart.totalKobo)}
                                </dd>
                            </div>
                        </dl>

                        <button
                            type="button"
                            onClick={checkout}
                            className="mt-3.5 w-full rounded-full bg-brand-600 py-3 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-[0.98]"
                        >
                            Checkout ({cart.itemCount})
                        </button>
                        <Link
                            href={route('cart.index')}
                            className="mt-2 block w-full rounded-full border border-gray-200 py-2.5 text-center text-sm font-bold text-gray-700 transition hover:border-brand-300 hover:text-brand-700"
                        >
                            View full cart
                        </Link>
                    </footer>
                )}
            </aside>
        </>
    );
}
