import { Card } from '@/Components/ui/Card';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, KeyRound, MapPin, Package, PartyPopper } from 'lucide-react';

interface TimelineEntry {
    id: number;
    status: string;
    label: string;
    note: string | null;
    at: string | null;
}

interface Props {
    order: {
        uuid: string;
        productName: string;
        productSlug: string;
        productImage: string | null;
        status: string;
        statusLabel: string;
        lockedPriceKobo: number;
        deliveryAddress: string;
        state: string;
        lga: string;
        createdAt: string;
        deliveredAt: string | null;
        confirmedAt: string | null;
        canConfirmReceipt: boolean;
        goodsDueKobo: number;
        goodsPaidAt: string | null;
        /** Four digits the customer reads to the courier. Null once spent. */
        deliveryCode: string | null;
        timeline: TimelineEntry[];
    };
    [key: string]: unknown;
}

/** The forward chain used to render the progress steps. */
const CHAIN = ['pending', 'processing', 'ready_for_pickup', 'packed', 'shipped', 'out_for_delivery', 'delivered'];

export default function OrderShow() {
    const { order } = usePage<Props>().props;
    const confirmForm = useForm({});
    const payGoodsForm = useForm({});

    const chainIndex = CHAIN.indexOf(order.status);
    const isProblem = order.status === 'vendor_rejected' || order.status === 'cancelled';

    return (
        <AccountLayout title="Order tracking">
            <Head title={`Order — ${order.productName}`} />

            <Link
                href={route('orders.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> All orders
            </Link>

            {/* ── Order hero ── */}
            <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center gap-4">
                    <span className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                        {order.productImage ? (
                            <img src={order.productImage} alt="" className="h-full w-full object-cover" />
                        ) : (
                            <Package className="h-7 w-7 text-gray-300" />
                        )}
                    </span>
                    <div className="min-w-0 flex-1">
                        <Link
                            href={route('catalog.product', order.productSlug)}
                            className="block truncate text-base font-bold text-gray-900 hover:text-brand-700"
                        >
                            {order.productName}
                        </Link>
                        <p className="mt-0.5 text-xs text-gray-400">
                            Order {order.uuid.slice(0, 8).toUpperCase()} · placed {order.createdAt} ·{' '}
                            {formatNairaFromKobo(order.lockedPriceKobo)}
                        </p>
                        <p className="mt-1 flex items-center gap-1 text-xs text-gray-500">
                            <MapPin className="h-3.5 w-3.5 text-brand-600" />
                            {order.deliveryAddress}, {order.lga}, {order.state}
                        </p>
                    </div>
                    <span
                        className={cn(
                            'rounded-full px-3 py-1 text-xs font-bold',
                            isProblem ? 'bg-red-50 text-red-700' : 'bg-brand-50 text-brand-700',
                        )}
                    >
                        {order.statusLabel}
                    </span>
                </div>

                {/* ── Progress steps ── */}
                {!isProblem && (
                    <div className="mt-5 flex items-center">
                        {CHAIN.map((step, index) => (
                            <div key={step} className={cn('flex items-center', index > 0 && 'flex-1')}>
                                {index > 0 && (
                                    <span
                                        className={cn(
                                            'h-1 flex-1 rounded-full',
                                            index <= chainIndex ? 'bg-brand-600' : 'bg-gray-100',
                                        )}
                                    />
                                )}
                                <span
                                    className={cn(
                                        'mx-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold',
                                        index < chainIndex
                                            ? 'bg-brand-600 text-white'
                                            : index === chainIndex
                                              ? 'bg-brand-600 text-white ring-4 ring-brand-100'
                                              : 'bg-gray-100 text-gray-400',
                                    )}
                                >
                                    {index < chainIndex ? '✓' : index + 1}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* ── Delivery code ──
                Shown for the whole journey, not just the last hour: the
                courier can arrive early, and a customer hunting for a code
                on a doorstep is the moment this is most likely to fail. */}
            {order.deliveryCode && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-200 bg-brand-50 px-5 py-4">
                    <div>
                        <p className="flex items-center gap-2 text-sm font-bold text-brand-900">
                            <KeyRound className="h-4 w-4 text-brand-600" />
                            Your delivery code
                        </p>
                        <p className="mt-0.5 text-xs text-brand-700">
                            Read this to the courier when they hand your parcel over. Do not give it
                            to anyone before you have the goods in your hands.
                        </p>
                    </div>
                    <span className="rounded-xl bg-white px-5 py-2.5 text-2xl font-extrabold tracking-[0.35em] tabular-nums text-brand-800 shadow-sm">
                        {order.deliveryCode}
                    </span>
                </div>
            )}

            {/* ── Confirm receipt ── */}
            {order.canConfirmReceipt && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4">
                    <p className="flex items-center gap-2 text-sm text-emerald-900">
                        <PartyPopper className="h-5 w-5 text-emerald-600" />
                        Delivered {order.deliveredAt}. Everything in order? Confirm to close out your purchase.
                    </p>
                    <button
                        type="button"
                        disabled={confirmForm.processing}
                        onClick={() =>
                            confirmForm.post(route('orders.confirm-receipt', order.uuid), { preserveScroll: true })
                        }
                        className="rounded-full bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700 active:scale-95 disabled:opacity-60"
                    >
                        {confirmForm.processing ? 'Confirming…' : 'Confirm receipt'}
                    </button>
                </div>
            )}

            {order.confirmedAt && (
                <p className="mt-4 flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-800">
                    <CheckCircle2 className="h-4 w-4" /> Receipt confirmed {order.confirmedAt}. Thanks for shopping
                    with FirstMaket!
                </p>
            )}

            {order.status === 'delivered' && order.goodsDueKobo > 0 && !order.goodsPaidAt && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                    <div>
                        <p className="text-sm font-bold text-amber-900">Payment due for your item</p>
                        <p className="mt-0.5 text-xs text-amber-700">
                            Your delivery fee was paid at checkout. Pay the item balance securely now.
                        </p>
                    </div>
                    <button
                        type="button"
                        disabled={payGoodsForm.processing}
                        onClick={() => payGoodsForm.post(route('orders.pay-goods', order.uuid), { preserveScroll: true })}
                        className="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 disabled:opacity-60"
                    >
                        {payGoodsForm.processing ? 'Opening payment…' : `Pay ${formatNairaFromKobo(order.goodsDueKobo)}`}
                    </button>
                </div>
            )}

            {order.goodsPaidAt && (
                <p className="mt-4 flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-800">
                    <CheckCircle2 className="h-4 w-4" /> Item payment confirmed {order.goodsPaidAt}.
                </p>
            )}

            {/* ── Timeline ── */}
            <Card className="mt-4 p-0">
                <h2 className="border-b border-gray-100 px-5 py-4 text-sm font-bold text-gray-900">
                    Delivery timeline
                </h2>
                <ul className="px-5 py-4">
                    {order.timeline.map((entry, index) => (
                        <li key={entry.id} className="relative flex gap-3 pb-5 last:pb-0">
                            {index < order.timeline.length - 1 && (
                                <span className="absolute left-[9px] top-5 h-full w-0.5 bg-gray-100" aria-hidden="true" />
                            )}
                            <span
                                className={cn(
                                    'relative z-[1] mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full',
                                    index === order.timeline.length - 1
                                        ? 'bg-brand-600 text-white'
                                        : 'bg-brand-100 text-brand-600',
                                )}
                            >
                                <CheckCircle2 className="h-3.5 w-3.5" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-gray-900">{entry.label}</p>
                                {entry.note && <p className="text-xs text-gray-500">{entry.note}</p>}
                                {entry.at && <p className="mt-0.5 text-xs text-gray-400">{entry.at}</p>}
                            </div>
                        </li>
                    ))}
                </ul>
            </Card>
        </AccountLayout>
    );
}
