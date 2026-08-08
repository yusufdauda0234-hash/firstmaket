import BulkActionBar from '@/Components/ui/BulkActionBar';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination } from '@/Components/ui/Pagination';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import ViewToggle from '@/Components/ui/ViewToggle';
import { useRowSelection } from '@/Hooks/useRowSelection';
import { useViewMode } from '@/Hooks/useViewMode';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Paginated } from '@/Types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ChevronRight } from 'lucide-react';

interface OrderRow {
    uuid: string;
    productName: string;
    vendorName: string;
    customerName: string;
    status: string;
    statusLabel: string;
    lockedPriceKobo: number;
    prepareOverdue: boolean;
    createdAt: string;
}

interface Props {
    orders: Paginated<OrderRow>;
    filters: { status: string | null };
    pendingCount: number;
    rejectedCount: number;
    overdueCount: number;
    [key: string]: unknown;
}

const TABS = [
    { value: '', label: 'All' },
    { value: 'pending', label: 'Awaiting confirmation' },
    { value: 'processing', label: 'Processing' },
    { value: 'vendor_rejected', label: 'Vendor rejected' },
    { value: 'delivered', label: 'Delivered' },
];

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

export default function AdminOrdersIndex() {
    const { orders, filters, pendingCount, rejectedCount, overdueCount } = usePage<Props>().props;
    const { mode, choose } = useViewMode('admin.orders', 'table');

    // Only pending orders can be confirmed, so only those are selectable —
    // offering a tick that would be skipped is worse than not offering it.
    const confirmable = orders.data.filter((order) => order.status === 'pending');
    const selection = useRowSelection(confirmable.map((order) => order.uuid));
    const bulk = useForm<{ uuids: string[] }>({ uuids: [] });
    const firstIndex = (orders.from ?? 1) - 1;

    function confirmSelected() {
        bulk.transform(() => ({ uuids: selection.ids }));
        bulk.post(route('admin.orders.bulk-confirm'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    const apply = (status: string) =>
        router.get(route('admin.orders.index'), status ? { status } : {}, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <AdminLayout>
            <Head title="Orders" />

            <PageHeader
                eyebrow="Fulfillment"
                title="Orders"
                description="Confirm paid orders, watch preparation SLAs, and resolve vendor rejections."
                actions={
                    <div className="flex flex-wrap items-center gap-2 text-xs font-bold">
                        <ViewToggle mode={mode} onChange={choose} label="orders" />
                        {pendingCount > 0 && (
                            <span className="rounded-full bg-brand-yellow px-3 py-1.5 text-brand-900">
                                {pendingCount} to confirm
                            </span>
                        )}
                        {overdueCount > 0 && (
                            <span className="rounded-full bg-red-100 px-3 py-1.5 text-red-700">
                                {overdueCount} SLA overdue
                            </span>
                        )}
                        {rejectedCount > 0 && (
                            <span className="rounded-full bg-amber-100 px-3 py-1.5 text-amber-800">
                                {rejectedCount} rejected
                            </span>
                        )}
                    </div>
                }
            />

            <div className="mb-4 flex flex-wrap gap-2">
                {TABS.map((tab) => (
                    <button
                        key={tab.value}
                        type="button"
                        onClick={() => apply(tab.value)}
                        className={
                            (filters.status ?? '') === tab.value
                                ? 'rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm'
                                : 'rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-600 transition hover:border-brand-300 hover:text-brand-700'
                        }
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            <Card className="overflow-hidden p-0">
                {orders.data.length === 0 ? (
                    <p className="px-6 py-14 text-center text-sm text-gray-500">No orders match this filter.</p>
                ) : mode === 'table' ? (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[860px] text-sm">
                            <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="w-10 py-3 pl-5 pr-2">
                                        <RowCheckbox
                                            checked={selection.allSelected}
                                            indeterminate={selection.someSelected}
                                            onChange={selection.toggleAll}
                                            label="Select all orders awaiting confirmation"
                                        />
                                    </th>
                                    <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                    <th className="px-5 py-3 font-semibold">Order</th>
                                    <th className="px-4 py-3 font-semibold">Vendor → Customer</th>
                                    <th className="px-4 py-3 text-right font-semibold">Value</th>
                                    <th className="px-4 py-3 font-semibold">Placed</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="w-10 px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {orders.data.map((order, index) => (
                                    <tr
                                        key={order.uuid}
                                        onClick={() => router.visit(route('admin.orders.show', order.uuid))}
                                        className={cn(
                                            'group cursor-pointer transition-colors hover:bg-brand-50/50',
                                            selection.isSelected(order.uuid) && 'bg-brand-50/70',
                                        )}
                                    >
                                        <td className="py-3.5 pl-5 pr-2">
                                            {/* Only a pending order can be confirmed. */}
                                            {order.status === 'pending' && (
                                                <RowCheckbox
                                                    checked={selection.isSelected(order.uuid)}
                                                    onChange={() => selection.toggle(order.uuid)}
                                                    label={`Select ${order.productName}`}
                                                />
                                            )}
                                        </td>
                                        <td className="px-2 py-3.5 text-xs tabular-nums text-gray-400">
                                            {firstIndex + index + 1}
                                        </td>
                                        <td className="px-5 py-3.5">
                                            <span className="flex items-center gap-2 font-semibold text-gray-900 group-hover:text-brand-700">
                                                <span className="line-clamp-1">{order.productName}</span>
                                                {order.prepareOverdue && (
                                                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700">
                                                        <AlertTriangle className="h-3 w-3" /> SLA
                                                    </span>
                                                )}
                                            </span>
                                            <span className="mt-0.5 block font-mono text-xs text-gray-400">
                                                {order.uuid.slice(0, 8).toUpperCase()}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-gray-600">
                                            {order.vendorName}
                                            <span className="block text-xs text-gray-400">
                                                → {order.customerName}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-right font-bold tabular-nums text-gray-900">
                                            {formatNairaFromKobo(order.lockedPriceKobo)}
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-gray-500">{order.createdAt}</td>
                                        <td className="px-4 py-3.5">
                                            <span
                                                className={cn(
                                                    'rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                                    statusStyle[order.status] ?? 'bg-gray-100 text-gray-500',
                                                )}
                                            >
                                                {order.statusLabel}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3.5">
                                            <ChevronRight className="h-4 w-4 text-gray-300 transition-transform group-hover:translate-x-1 group-hover:text-brand-500" />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
                        {orders.data.map((order) => (
                            <div
                                key={order.uuid}
                                className={cn(
                                    'flex flex-col rounded-xl border p-4 transition',
                                    selection.isSelected(order.uuid)
                                        ? 'border-brand-300 bg-brand-50/60'
                                        : 'border-gray-100 hover:border-brand-200 hover:shadow-md hover:shadow-brand-600/5',
                                )}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    {order.status === 'pending' ? (
                                        <RowCheckbox
                                            checked={selection.isSelected(order.uuid)}
                                            onChange={() => selection.toggle(order.uuid)}
                                            label={`Select ${order.productName}`}
                                        />
                                    ) : (
                                        <span />
                                    )}
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                            statusStyle[order.status] ?? 'bg-gray-100 text-gray-500',
                                        )}
                                    >
                                        {order.statusLabel}
                                    </span>
                                </div>

                                <Link href={route('admin.orders.show', order.uuid)} className="group mt-3 block">
                                    <span className="flex items-center gap-2 font-bold text-gray-900 group-hover:text-brand-700">
                                        <span className="line-clamp-2">{order.productName}</span>
                                        {order.prepareOverdue && (
                                            <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-red-600" />
                                        )}
                                    </span>
                                    <span className="mt-1 block truncate text-sm text-gray-500">
                                        {order.vendorName} → {order.customerName}
                                    </span>
                                </Link>

                                <span className="mt-3 flex items-baseline justify-between border-t border-gray-100 pt-2.5">
                                    <span className="font-bold tabular-nums text-gray-900">
                                        {formatNairaFromKobo(order.lockedPriceKobo)}
                                    </span>
                                    <span className="text-xs text-gray-400">{order.createdAt}</span>
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            <BulkActionBar
                count={selection.count}
                noun="order"
                processing={bulk.processing}
                onClear={selection.clear}
                actions={[{ label: 'Confirm', tone: 'primary', run: confirmSelected }]}
            />

            <Pagination links={orders.links} />
        </AdminLayout>
    );
}
