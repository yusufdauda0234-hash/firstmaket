import { Card } from '@/Components/ui/Card';
import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Minus, PiggyBank, Plus, ShoppingBag, Store, Trash2, Wallet } from 'lucide-react';
import { useState } from 'react';

interface CartItemRow {
    id: number;
    productUuid: string;
    productName: string;
    productSlug: string;
    productImage: string | null;
    vendorName: string;
    priceKobo: number;
    quantity: number;
    lineTotalKobo: number;
    stockQuantity: number;
}

interface Props {
    items: CartItemRow[];
    [key: string]: unknown;
}

export default function CartIndex() {
    const { items } = usePage<Props>().props;
    const [pendingId, setPendingId] = useState<number | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [bundling, setBundling] = useState(false);

    const toggleSelected = (itemId: number) => {
        setSelected((current) => (current.includes(itemId) ? current.filter((id) => id !== itemId) : [...current, itemId]));
    };

    const selectedItems = items.filter((item) => selected.includes(item.id));

    const startBundle = () => {
        setBundling(true);
        router.post(
            route('cart.bundle-plan.setup'),
            { items: selected },
            { onFinish: () => setBundling(false) },
        );
    };

    const groups = items.reduce<Record<string, CartItemRow[]>>((acc, item) => {
        (acc[item.vendorName] ??= []).push(item);
        return acc;
    }, {});

    const grandTotalKobo = items.reduce((sum, item) => sum + item.lineTotalKobo, 0);

    const setQuantity = (item: CartItemRow, quantity: number) => {
        if (quantity < 1 || quantity > item.stockQuantity) return;

        setPendingId(item.id);
        router.patch(
            route('cart.items.update', item.id),
            { quantity },
            { preserveScroll: true, onFinish: () => setPendingId(null) },
        );
    };

    const removeItem = (item: CartItemRow) => {
        setPendingId(item.id);
        router.delete(route('cart.items.destroy', item.id), {
            preserveScroll: true,
            onFinish: () => setPendingId(null),
        });
    };

    return (
        <AccountLayout title="My Cart">
            <Head title="My Cart" />

            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">My Cart</h1>
            <p className="mt-1 text-sm text-gray-500">
                Items you've added, grouped by vendor. Nothing is charged until you check out.
            </p>

            {items.length === 0 ? (
                <Card className="mt-6">
                    <div className="flex flex-col items-center px-6 py-14 text-center">
                        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                            <ShoppingBag className="h-7 w-7" />
                        </span>
                        <p className="mt-4 text-sm font-medium text-gray-900">Your cart is empty</p>
                        <p className="mt-1 text-sm text-gray-500">Browse products and add something to get started.</p>
                        <Link
                            href={route('catalog.index')}
                            className="mt-4 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                        >
                            Browse products
                        </Link>
                    </div>
                </Card>
            ) : (
                <div className="mt-6 space-y-5">
                    {Object.entries(groups).map(([vendorName, vendorItems]) => (
                        <Card key={vendorName} className="overflow-hidden p-0">
                            <div className="flex items-center gap-2 border-b border-gray-100 px-5 py-3">
                                <Store className="h-4 w-4 text-gray-400" />
                                <span className="text-sm font-bold text-gray-900">{vendorName}</span>
                            </div>
                            <ul className="divide-y divide-gray-100">
                                {vendorItems.map((item) => (
                                    <li key={item.id} className="flex items-center gap-4 px-5 py-4">
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(item.id)}
                                            onChange={() => toggleSelected(item.id)}
                                            aria-label={`Select ${item.productName} for a Pay Small plan`}
                                            className="h-4 w-4 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-brand-500/30"
                                        />
                                        <Link
                                            href={route('catalog.product', item.productSlug)}
                                            className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50"
                                        >
                                            {item.productImage ? (
                                                <img src={item.productImage} alt="" className="h-full w-full object-cover" />
                                            ) : (
                                                <ShoppingBag className="h-6 w-6 text-gray-300" />
                                            )}
                                        </Link>
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={route('catalog.product', item.productSlug)}
                                                className="truncate text-sm font-semibold text-gray-900 hover:text-brand-600"
                                            >
                                                {item.productName}
                                            </Link>
                                            <p className="mt-1 text-sm text-gray-500">{formatNairaFromKobo(item.priceKobo)}</p>

                                            <div className="mt-2 flex items-center gap-1 rounded-full border border-gray-200 px-1.5 py-1 w-fit">
                                                <button
                                                    type="button"
                                                    onClick={() => setQuantity(item, item.quantity - 1)}
                                                    disabled={pendingId === item.id || item.quantity <= 1}
                                                    aria-label="Decrease quantity"
                                                    className="flex h-6 w-6 items-center justify-center rounded-full text-gray-600 transition hover:bg-slate-100 active:scale-90 disabled:cursor-not-allowed disabled:opacity-40"
                                                >
                                                    <Minus className="h-3 w-3" />
                                                </button>
                                                <span className="w-6 text-center text-sm font-semibold text-gray-900">
                                                    {item.quantity}
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => setQuantity(item, item.quantity + 1)}
                                                    disabled={pendingId === item.id || item.quantity >= item.stockQuantity}
                                                    aria-label="Increase quantity"
                                                    className="flex h-6 w-6 items-center justify-center rounded-full text-gray-600 transition hover:bg-slate-100 active:scale-90 disabled:cursor-not-allowed disabled:opacity-40"
                                                >
                                                    <Plus className="h-3 w-3" />
                                                </button>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <p className="font-bold text-gray-900">
                                                {formatNairaFromKobo(item.lineTotalKobo)}
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => removeItem(item)}
                                                disabled={pendingId === item.id}
                                                className="mt-1 inline-flex items-center gap-1 text-xs text-gray-400 transition hover:text-red-600 disabled:opacity-50"
                                            >
                                                <Trash2 className="h-3 w-3" /> Remove
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    ))}

                    <Card>
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-semibold text-gray-900">Cart total</span>
                            <span className="text-lg font-extrabold text-gray-900">
                                {formatNairaFromKobo(grandTotalKobo)}
                            </span>
                        </div>
                        <p className="mt-2 text-xs text-gray-400">
                            Check the box on any item to bundle it into a Pay Small plan, or pay for the whole cart now.
                        </p>
                        <Link
                            href={route('cart.checkout')}
                            className="mt-4 flex w-full items-center justify-center gap-2 rounded-full bg-brand-600 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-[0.98]"
                        >
                            <Wallet className="h-4 w-4" /> Pay in full — {formatNairaFromKobo(grandTotalKobo)}
                        </Link>
                    </Card>

                    {selectedItems.length > 0 && (
                        <Card className="border-brand-200 bg-brand-50/60">
                            <h3 className="flex items-center gap-2 text-sm font-bold text-gray-900">
                                <PiggyBank className="h-4 w-4 text-brand-600" /> Pay Small for {selectedItems.length}{' '}
                                selected item{selectedItems.length === 1 ? '' : 's'}
                            </h3>
                            {selectedItems.length === 1 ? (
                                selectedItems[0].quantity > 1 ? (
                                    <p className="mt-2 text-xs text-gray-500">
                                        Reduce the quantity to 1 before starting a plan for a single item.
                                    </p>
                                ) : (
                                    <Link
                                        href={route('savings.plans.start', selectedItems[0].productSlug)}
                                        className="mt-3 block w-full rounded-full border border-brand-300 bg-white py-2 text-center text-sm font-bold text-brand-700 transition hover:bg-brand-50 active:scale-[0.98]"
                                    >
                                        Save Small Small for this item →
                                    </Link>
                                )
                            ) : (
                                <>
                                    <p className="mt-1 text-xs text-gray-500">
                                        Combine their price into one target and save toward all of them together.
                                    </p>
                                    <button
                                        type="button"
                                        disabled={bundling}
                                        onClick={startBundle}
                                        className="mt-3 w-full rounded-full bg-brand-600 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-[0.98] disabled:opacity-60"
                                    >
                                        {bundling ? 'Loading…' : 'Bundle into one plan →'}
                                    </button>
                                </>
                            )}
                        </Card>
                    )}
                </div>
            )}
        </AccountLayout>
    );
}
