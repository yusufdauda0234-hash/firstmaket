import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination } from '@/Components/ui/Pagination';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
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
    orders: {
        data: OrderRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
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
                    <div className="flex flex-wrap gap-2 text-xs font-bold">
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
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {orders.data.map((order) => (
                            <li key={order.uuid}>
                                <Link
                                    href={route('admin.orders.show', order.uuid)}
                                    className="flex items-center gap-4 px-5 py-4 transition hover:bg-slate-50"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="flex items-center gap-2 truncate text-sm font-bold text-gray-900">
                                            {order.productName}
                                            {order.prepareOverdue && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700">
                                                    <AlertTriangle className="h-3 w-3" /> SLA
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-400">
                                            {order.uuid.slice(0, 8).toUpperCase()} · {order.vendorName} →{' '}
                                            {order.customerName} · {order.createdAt}
                                        </p>
                                    </div>
                                    <span className="text-sm font-bold text-gray-900">
                                        {formatNairaFromKobo(order.lockedPriceKobo)}
                                    </span>
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                            statusStyle[order.status] ?? 'bg-gray-100 text-gray-500',
                                        )}
                                    >
                                        {order.statusLabel}
                                    </span>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-gray-300" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Pagination links={orders.links} />
        </AdminLayout>
    );
}
