import { Card } from '@/Components/ui/Card';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Package, ShoppingBag } from 'lucide-react';

interface OrderRow {
    uuid: string;
    productName: string;
    productImage: string | null;
    status: string;
    statusLabel: string;
    lockedPriceKobo: number;
    createdAt: string;
    deliveredAt: string | null;
    confirmed: boolean;
}

interface Props {
    orders: OrderRow[];
    [key: string]: unknown;
}

const statusStyle: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-600',
    processing: 'bg-sky-50 text-sky-700',
    ready_for_pickup: 'bg-indigo-50 text-indigo-700',
    packed: 'bg-indigo-50 text-indigo-700',
    shipped: 'bg-violet-50 text-violet-700',
    out_for_delivery: 'bg-amber-50 text-amber-700',
    delivered: 'bg-emerald-50 text-emerald-700',
    vendor_rejected: 'bg-red-50 text-red-700',
    cancelled: 'bg-gray-100 text-gray-400',
};

export default function OrdersIndex() {
    const { orders } = usePage<Props>().props;

    return (
        <AccountLayout title="My Orders">
            <Head title="My Orders" />

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">My Orders</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Every purchase, tracked from payment to your doorstep.
                    </p>
                </div>
            </div>

            {orders.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Package className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No orders yet</p>
                    <p className="mt-1 max-w-sm text-sm text-gray-500">
                        Pay at once or finish a savings plan — your orders will show up here the moment you add a
                        delivery address.
                    </p>
                    <Link
                        href={route('catalog.index')}
                        className="mt-4 inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                    >
                        <ShoppingBag className="h-4 w-4" /> Start shopping
                    </Link>
                </Card>
            ) : (
                <div className="space-y-3">
                    {orders.map((order) => (
                        <Link
                            key={order.uuid}
                            href={route('orders.show', order.uuid)}
                            className="group flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md"
                        >
                            <span className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                {order.productImage ? (
                                    <img src={order.productImage} alt="" className="h-full w-full object-cover" />
                                ) : (
                                    <Package className="h-7 w-7 text-gray-300" />
                                )}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-bold text-gray-900 group-hover:text-brand-700">
                                    {order.productName}
                                </p>
                                <p className="mt-0.5 text-xs text-gray-400">
                                    Ordered {order.createdAt} · {formatNairaFromKobo(order.lockedPriceKobo)}
                                </p>
                                <span
                                    className={cn(
                                        'mt-1.5 inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                        statusStyle[order.status] ?? 'bg-gray-100 text-gray-500',
                                    )}
                                >
                                    {order.statusLabel}
                                </span>
                            </div>
                            <ArrowRight className="h-4 w-4 shrink-0 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600" />
                        </Link>
                    ))}
                </div>
            )}
        </AccountLayout>
    );
}
