import BulkActionBar from '@/Components/ui/BulkActionBar';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import ViewToggle from '@/Components/ui/ViewToggle';
import { useRowSelection } from '@/Hooks/useRowSelection';
import { useViewMode } from '@/Hooks/useViewMode';
import { Card } from '@/Components/ui/Card';
import VendorLayout from '@/Layouts/VendorLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, Package, PackageCheck, XCircle } from 'lucide-react';
import { useState } from 'react';

interface OrderRow {
    uuid: string;
    productName: string;
    productImage: string | null;
    status: string;
    statusLabel: string;
    lockedPriceKobo: number;
    vendorEarningKobo: number;
    prepareDueAt: string | null;
    prepareOverdue: boolean;
    stockConfirmed: boolean;
    soldAt: string;
    earningsCredited: boolean;
}

interface Props {
    orders: OrderRow[];
    toPrepareCount: number;
    [key: string]: unknown;
}

const statusStyle: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-600',
    processing: 'bg-sky-50 text-sky-700',
    ready_for_pickup: 'bg-indigo-50 text-indigo-700',
    packed: 'bg-violet-50 text-violet-700',
    shipped: 'bg-violet-50 text-violet-700',
    out_for_delivery: 'bg-amber-50 text-amber-700',
    delivered: 'bg-emerald-50 text-emerald-700',
    vendor_rejected: 'bg-red-50 text-red-700',
    cancelled: 'bg-gray-100 text-gray-400',
};

/** SLA countdown text from an ISO deadline. */
function slaText(prepareDueAt: string | null): string | null {
    if (!prepareDueAt) return null;
    const ms = new Date(prepareDueAt).getTime() - Date.now();
    if (ms <= 0) return 'SLA missed';
    const hours = Math.floor(ms / 3_600_000);
    return hours >= 24 ? `${Math.floor(hours / 24)}d ${hours % 24}h left` : `${hours}h left`;
}

export default function VendorOrdersIndex() {
    const { orders, toPrepareCount } = usePage<Props>().props;
    const actionForm = useForm({ reason: '' });
    const [rejecting, setRejecting] = useState<string | null>(null);

    // Orders lead with the photo of what has to be packed, so grid by default.
    const { mode, choose } = useViewMode('vendor.orders', 'grid');

    // Only an order still being prepared can be marked ready.
    const readyable = orders.filter((order) => order.status === 'processing');
    const selection = useRowSelection(readyable.map((order) => order.uuid));
    const bulk = useForm<{ uuids: string[] }>({ uuids: [] });

    function markSelectedReady() {
        bulk.transform(() => ({ uuids: selection.ids }));
        bulk.post(route('vendor.orders.bulk-ready'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    return (
        <VendorLayout>
            <Head title="Orders" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-extrabold tracking-tight text-gray-900">Orders</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Sold items to prepare — pack within the deadline and FirstMaket handles the delivery.
                        Buyer details are never shared.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <ViewToggle mode={mode} onChange={choose} label="orders" />
                    {toPrepareCount > 0 && (
                        <span className="rounded-full bg-brand-50 px-4 py-2 text-sm font-bold text-brand-700">
                            {toPrepareCount} to prepare
                        </span>
                    )}
                </div>
            </div>

            {orders.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Package className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No orders yet</p>
                    <p className="mt-1 max-w-sm text-sm text-gray-500">
                        When a customer buys or fully funds a plan for one of your products, it appears here
                        instantly.
                    </p>
                </Card>
            ) : mode === 'table' ? (
                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[880px] text-sm">
                            <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="w-10 py-3 pl-5 pr-2">
                                        <RowCheckbox
                                            checked={selection.allSelected}
                                            indeterminate={selection.someSelected}
                                            onChange={selection.toggleAll}
                                            label="Select all orders to prepare"
                                        />
                                    </th>
                                    <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                    <th className="px-5 py-3 font-semibold">Product</th>
                                    <th className="px-4 py-3 font-semibold">Sold</th>
                                    <th className="px-4 py-3 text-right font-semibold">You earn</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-5 py-3 text-right font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {orders.map((order, index) => {
                                    const actionable = order.status === 'processing';

                                    return (
                                        <tr
                                            key={order.uuid}
                                            className={cn(
                                                'transition-colors hover:bg-brand-50/40',
                                                selection.isSelected(order.uuid) && 'bg-brand-50/70',
                                            )}
                                        >
                                            <td className="py-3.5 pl-5 pr-2">
                                                {actionable && (
                                                    <RowCheckbox
                                                        checked={selection.isSelected(order.uuid)}
                                                        onChange={() => selection.toggle(order.uuid)}
                                                        label={'Select ' + order.productName}
                                                    />
                                                )}
                                            </td>
                                            <td className="px-2 py-3.5 text-xs tabular-nums text-gray-400">
                                                {index + 1}
                                            </td>
                                            <td className="px-5 py-3.5">
                                                <div className="flex items-center gap-3">
                                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-50">
                                                        {order.productImage ? (
                                                            <img
                                                                src={order.productImage}
                                                                alt=""
                                                                className="h-full w-full object-cover"
                                                            />
                                                        ) : (
                                                            <Package className="h-4 w-4 text-gray-300" />
                                                        )}
                                                    </span>
                                                    <span className="min-w-0">
                                                        <span className="line-clamp-1 font-semibold text-gray-900">
                                                            {order.productName}
                                                        </span>
                                                        <span className="block font-mono text-xs text-gray-400">
                                                            {order.uuid.slice(0, 8).toUpperCase()}
                                                        </span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3.5 text-xs text-gray-500">{order.soldAt}</td>
                                            <td className="px-4 py-3.5 text-right font-bold tabular-nums text-emerald-600">
                                                {formatNairaFromKobo(order.vendorEarningKobo)}
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <span
                                                    className={cn(
                                                        'rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                                        statusStyle[order.status] ?? 'bg-gray-100 text-gray-500',
                                                    )}
                                                >
                                                    {order.statusLabel}
                                                </span>
                                                {actionable && order.prepareOverdue && (
                                                    <span className="mt-1 flex items-center gap-1 text-[11px] font-bold text-red-700">
                                                        <AlertTriangle className="h-3 w-3" /> overdue
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-5 py-3.5 text-right">
                                                {actionable ? (
                                                    <button
                                                        type="button"
                                                        disabled={actionForm.processing}
                                                        onClick={() =>
                                                            actionForm.post(route('vendor.orders.ready', order.uuid), {
                                                                preserveScroll: true,
                                                            })
                                                        }
                                                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                                                    >
                                                        <PackageCheck className="h-3.5 w-3.5" /> Ready
                                                    </button>
                                                ) : (
                                                    <span className="text-xs text-gray-300">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </Card>
            ) : (
                <div className="space-y-3">
                    {orders.map((order) => {
                        const actionable = order.status === 'processing';
                        return (
                            <Card key={order.uuid} className="p-4">
                                <div className="flex flex-wrap items-center gap-4">
                                    {actionable && (
                                        <RowCheckbox
                                            checked={selection.isSelected(order.uuid)}
                                            onChange={() => selection.toggle(order.uuid)}
                                            label={`Select ${order.productName}`}
                                        />
                                    )}
                                    <span className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                        {order.productImage ? (
                                            <img src={order.productImage} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <Package className="h-6 w-6 text-gray-300" />
                                        )}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-bold text-gray-900">{order.productName}</p>
                                        <p className="mt-0.5 text-xs text-gray-400">
                                            Order {order.uuid.slice(0, 8).toUpperCase()} · sold {order.soldAt} ·{' '}
                                            {formatNairaFromKobo(order.lockedPriceKobo)}
                                            <span className="text-emerald-600">
                                                {' '}
                                                (you earn {formatNairaFromKobo(order.vendorEarningKobo)})
                                            </span>
                                        </p>
                                        <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                            <span
                                                className={cn(
                                                    'rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                                    statusStyle[order.status] ?? 'bg-gray-100 text-gray-500',
                                                )}
                                            >
                                                {order.statusLabel}
                                            </span>
                                            {actionable && order.prepareDueAt && (
                                                <span
                                                    className={cn(
                                                        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                                        order.prepareOverdue
                                                            ? 'bg-red-50 text-red-700'
                                                            : 'bg-amber-50 text-amber-700',
                                                    )}
                                                >
                                                    {order.prepareOverdue ? (
                                                        <AlertTriangle className="h-3 w-3" />
                                                    ) : (
                                                        <Clock className="h-3 w-3" />
                                                    )}
                                                    {slaText(order.prepareDueAt)}
                                                </span>
                                            )}
                                            {order.earningsCredited && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700">
                                                    <CheckCircle2 className="h-3 w-3" /> Earnings credited
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {actionable && (
                                        <div className="flex flex-wrap items-center gap-2">
                                            {!order.stockConfirmed && (
                                                <button
                                                    type="button"
                                                    disabled={actionForm.processing}
                                                    onClick={() =>
                                                        actionForm.post(route('vendor.orders.confirm-stock', order.uuid), {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                    className="rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-brand-300 hover:text-brand-700 active:scale-95"
                                                >
                                                    Confirm stock
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                disabled={actionForm.processing}
                                                onClick={() =>
                                                    actionForm.post(route('vendor.orders.ready', order.uuid), {
                                                        preserveScroll: true,
                                                    })
                                                }
                                                className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                                            >
                                                <PackageCheck className="h-4 w-4" /> Ready for pickup
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setRejecting(rejecting === order.uuid ? null : order.uuid)}
                                                className="inline-flex items-center gap-1 rounded-full border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50 active:scale-95"
                                            >
                                                <XCircle className="h-4 w-4" /> Can't fulfil
                                            </button>
                                        </div>
                                    )}
                                </div>

                                {/* Rejection reason */}
                                {rejecting === order.uuid && (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            actionForm.post(route('vendor.orders.reject', order.uuid), {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    setRejecting(null);
                                                    actionForm.reset();
                                                },
                                            });
                                        }}
                                        className="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-red-50 p-3"
                                    >
                                        <input
                                            type="text"
                                            placeholder="Why can't this be fulfilled? (e.g. out of stock)"
                                            value={actionForm.data.reason}
                                            onChange={(e) => actionForm.setData('reason', e.target.value)}
                                            required
                                            autoFocus
                                            className="min-w-[240px] flex-1 rounded-full border-red-200 text-sm focus:border-red-400 focus:ring-red-400/20"
                                        />
                                        <button
                                            type="submit"
                                            disabled={actionForm.processing}
                                            className="rounded-full bg-red-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-700 active:scale-95 disabled:opacity-60"
                                        >
                                            Reject order
                                        </button>
                                    </form>
                                )}
                            </Card>
                        );
                    })}
                </div>
            )}

            <BulkActionBar
                count={selection.count}
                noun="order"
                processing={bulk.processing}
                onClear={selection.clear}
                actions={[{ label: 'Mark ready', tone: 'primary', run: markSelectedReady }]}
            />
        </VendorLayout>
    );
}
