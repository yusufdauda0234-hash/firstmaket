import { Card } from '@/Components/ui/Card';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Package, ShoppingBag, Store } from 'lucide-react';

interface GroupItem {
    uuid: string;
    name: string;
    image: string | null;
    vendorName: string | null;
    quantity: number;
    lineTotalKobo: number;
}

interface OrderGroup {
    reference: string;
    firstOrderUuid: string;
    placedAt: string;
    totalKobo: number;
    parcelCount: number;
    vendorCount: number;
    items: GroupItem[];
    status: { value: string; label: string; mixed: boolean };
}

interface Props {
    groups: OrderGroup[];
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

/**
 * One card per purchase, the way every large marketplace lists orders.
 *
 * A single payment across two vendors used to render as two unrelated cards,
 * because internally an order is one unit — that is what a vendor packs and a
 * courier carries. The units are still there, listed inside the card that
 * paid for them.
 */
export default function OrdersIndex() {
    const { groups } = usePage<Props>().props;

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

            {groups.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Package className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No orders yet</p>
                    <p className="mt-1 max-w-sm text-sm text-gray-500">
                        Pay at once or finish a Pay Small Small plan — your orders will show up here the moment
                        you add a delivery address.
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
                    {groups.map((group) => (
                        <div
                            key={group.reference}
                            className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
                        >
                            {/* ── Purchase header ── */}
                            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-gray-100 bg-slate-50/60 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-bold text-gray-900">
                                        Order #{group.reference}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        Placed {group.placedAt} · {formatNairaFromKobo(group.totalKobo)}
                                        {group.vendorCount > 1 && ` · ${group.vendorCount} sellers`}
                                    </p>
                                </div>
                                <span className="flex items-center gap-2">
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                            statusStyle[group.status.value] ?? 'bg-gray-100 text-gray-500',
                                        )}
                                    >
                                        {group.status.label}
                                    </span>
                                    {/* Parcels move at their own pace; saying so
                                        beats claiming a single status that is
                                        only true of some of them. */}
                                    {group.status.mixed && (
                                        <span className="rounded-full bg-white px-2.5 py-0.5 text-[11px] font-semibold text-gray-500 ring-1 ring-inset ring-gray-200">
                                            {group.parcelCount} parcels, different stages
                                        </span>
                                    )}
                                </span>
                            </div>

                            {/* ── Items ── */}
                            <ul className="divide-y divide-gray-100">
                                {group.items.map((item) => (
                                    <li key={item.uuid} className="flex items-center gap-4 px-4 py-3">
                                        <span className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                            {item.image ? (
                                                <img loading="lazy" decoding="async"
                                                    src={item.image}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <Package className="h-7 w-7 text-gray-300" />
                                            )}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-gray-900">
                                                {item.name}
                                            </p>
                                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                {item.vendorName && (
                                                    <span className="inline-flex max-w-full items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700 ring-1 ring-inset ring-brand-100">
                                                        <Store className="h-3 w-3 shrink-0" aria-hidden="true" />
                                                        <span className="truncate">{item.vendorName}</span>
                                                    </span>
                                                )}
                                                <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-gray-600">
                                                    Qty {item.quantity}
                                                </span>
                                            </div>
                                        </div>
                                        <span className="shrink-0 text-sm font-bold tabular-nums text-gray-900">
                                            {formatNairaFromKobo(item.lineTotalKobo)}
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            <div className="border-t border-gray-100 px-4 py-2.5 text-right">
                                <Link
                                    href={route('orders.show', group.firstOrderUuid)}
                                    className="group inline-flex items-center gap-1.5 text-sm font-bold text-brand-600 transition hover:text-brand-700"
                                >
                                    Track this order
                                    <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" />
                                </Link>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </AccountLayout>
    );
}
