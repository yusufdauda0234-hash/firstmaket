import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, PiggyBank, Truck } from 'lucide-react';

interface TimelineEntry {
    id: number;
    status: string;
    label: string;
    note: string | null;
    at: string | null;
}

interface PrepEntry {
    id: number;
    status: string;
    note: string | null;
    at: string | null;
}

interface Props {
    order: {
        uuid: string;
        productName: string;
        vendorName: string;
        customerName: string;
        planUuid: string | null;
        status: string;
        statusLabel: string;
        lockedPriceKobo: number;
        commissionRatePercent: string;
        commissionSource: string;
        commissionReason: string;
        commissionKobo: number;
        vendorEarningKobo: number;
        deliveryAddress: string;
        state: string;
        lga: string;
        prepareDueAt: string | null;
        prepareOverdue: boolean;
        confirmedAt: string | null;
        deliveredAt: string | null;
        deliveryConfirmedAt: string | null;
        earningsCreditedAt: string | null;
        createdAt: string;
        timeline: TimelineEntry[];
        preparation: PrepEntry[];
        assignedLogistics: { id: number; name: string } | null;
    };
    logisticsUsers: { id: number; name: string }[];
    [key: string]: unknown;
}

function DetailRow({ label, value }: { label: React.ReactNode; value: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-3 py-2 text-sm">
            <dt className="text-gray-500">{label}</dt>
            <dd className="text-right font-semibold text-gray-900">{value}</dd>
        </div>
    );
}

export default function AdminOrderShow() {
    const { order, logisticsUsers } = usePage<Props>().props;
    const actionForm = useForm({});
    const assignForm = useForm({ logistics_user_id: '' });

    const canAssign = ['processing', 'ready_for_pickup', 'packed'].includes(order.status);

    return (
        <AdminLayout>
            <Head title={`Order ${order.uuid.slice(0, 8).toUpperCase()}`} />

            <Link
                href={route('admin.orders.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> All orders
            </Link>

            <PageHeader
                eyebrow={`Order ${order.uuid.slice(0, 8).toUpperCase()} · ${order.createdAt}`}
                title={order.productName}
                description={`${order.vendorName} → ${order.customerName}`}
                actions={
                    <span className="rounded-full bg-brand-50 px-3 py-1.5 text-xs font-bold text-brand-700">
                        {order.statusLabel}
                    </span>
                }
            />

            {/* ── Action bar ── */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                {order.status === 'pending' && (
                    <button
                        type="button"
                        disabled={actionForm.processing}
                        onClick={() =>
                            actionForm.post(route('admin.orders.confirm', order.uuid), { preserveScroll: true })
                        }
                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                    >
                        <CheckCircle2 className="h-4 w-4" /> Confirm order (start vendor SLA)
                    </button>
                )}
                {order.status === 'vendor_rejected' && (
                    <button
                        type="button"
                        disabled={actionForm.processing}
                        onClick={() =>
                            actionForm.post(route('admin.orders.resolve-rejection', order.uuid), {
                                preserveScroll: true,
                            })
                        }
                        className="inline-flex items-center gap-1.5 rounded-full bg-amber-500 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-amber-600 active:scale-95"
                    >
                        <PiggyBank className="h-4 w-4" /> Cancel + refund to Open Savings
                    </button>
                )}
                {canAssign && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            assignForm.post(route('admin.orders.assign-logistics', order.uuid), {
                                preserveScroll: true,
                                onSuccess: () => assignForm.reset(),
                            });
                        }}
                        className="flex items-center gap-2"
                    >
                        <Select
                            value={assignForm.data.logistics_user_id}
                            onChange={(e) => assignForm.setData('logistics_user_id', e.target.value)}
                            required
                            aria-label="Assign logistics personnel"
                            className="rounded-full"
                        >
                            <option value="">
                                {order.assignedLogistics
                                    ? `Assigned: ${order.assignedLogistics.name} (reassign…)`
                                    : 'Assign logistics…'}
                            </option>
                            {logisticsUsers.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </Select>
                        <button
                            type="submit"
                            disabled={assignForm.processing || assignForm.data.logistics_user_id === ''}
                            className="inline-flex items-center gap-1.5 rounded-full border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm font-bold text-brand-700 transition hover:bg-brand-100 active:scale-95 disabled:opacity-50"
                        >
                            <Truck className="h-4 w-4" /> Assign
                        </button>
                    </form>
                )}
                {order.prepareOverdue && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700">
                        <AlertTriangle className="h-3.5 w-3.5" /> Preparation SLA missed ({order.prepareDueAt})
                    </span>
                )}
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {/* ── Money snapshot ── */}
                <Card>
                    <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Money (snapshots)</h2>
                    <dl className="mt-2 divide-y divide-gray-50">
                        <DetailRow label="Locked price" value={formatNairaFromKobo(order.lockedPriceKobo)} />
                        {/* The rate alone made people come and ask where the
                            figure came from, so it says which rule set it. */}
                        <DetailRow
                            label={
                                <>
                                    Commission ({order.commissionRatePercent}%)
                                    <span className="mt-0.5 flex items-center gap-1.5">
                                        <span
                                            className={`rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
                                                order.commissionSource === 'vendor'
                                                    ? 'bg-indigo-50 text-indigo-700'
                                                    : order.commissionSource === 'category'
                                                      ? 'bg-sky-50 text-sky-700'
                                                      : 'bg-gray-100 text-gray-500'
                                            }`}
                                        >
                                            {order.commissionSource}
                                        </span>
                                        <span className="text-[11px] font-normal text-gray-400">
                                            {order.commissionReason}
                                        </span>
                                    </span>
                                </>
                            }
                            value={formatNairaFromKobo(order.commissionKobo)}
                        />
                        <DetailRow label="Vendor earning" value={formatNairaFromKobo(order.vendorEarningKobo)} />
                        <DetailRow
                            label="Earnings credited"
                            value={order.earningsCreditedAt ?? 'Not yet (awaits delivery confirmation)'}
                        />
                        <DetailRow
                            label="Plan"
                            value={
                                order.planUuid ? (
                                    <span className="font-mono text-xs">{order.planUuid.slice(0, 8).toUpperCase()}</span>
                                ) : (
                                    'Cart checkout'
                                )
                            }
                        />
                    </dl>
                </Card>

                {/* ── Delivery ── */}
                <Card>
                    <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Delivery</h2>
                    <dl className="mt-2 divide-y divide-gray-50">
                        <DetailRow label="Address" value={order.deliveryAddress} />
                        <DetailRow label="LGA / State" value={`${order.lga}, ${order.state}`} />
                        <DetailRow label="Confirmed (admin)" value={order.confirmedAt ?? '—'} />
                        <DetailRow label="Prepare due" value={order.prepareDueAt ?? '—'} />
                        <DetailRow label="Delivered" value={order.deliveredAt ?? '—'} />
                        <DetailRow label="Receipt confirmed" value={order.deliveryConfirmedAt ?? '—'} />
                    </dl>
                </Card>

                {/* ── Status timeline ── */}
                <Card className="p-0">
                    <h2 className="border-b border-gray-100 px-5 py-4 text-sm font-bold text-gray-900">
                        Status timeline
                    </h2>
                    <ul className="px-5 py-4">
                        {order.timeline.map((entry, index) => (
                            <li key={entry.id} className="relative flex gap-3 pb-4 last:pb-0">
                                {index < order.timeline.length - 1 && (
                                    <span className="absolute left-[9px] top-5 h-full w-0.5 bg-gray-100" aria-hidden="true" />
                                )}
                                <span className="relative z-[1] mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-600">
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

                {/* ── Vendor preparation trail ── */}
                <Card className="p-0">
                    <h2 className="border-b border-gray-100 px-5 py-4 text-sm font-bold text-gray-900">
                        Vendor preparation
                    </h2>
                    {order.preparation.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-gray-400">No preparation events yet.</p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {order.preparation.map((entry) => (
                                <li key={entry.id} className="flex items-center gap-3 px-5 py-3">
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase',
                                            entry.status === 'sla_breached' || entry.status === 'rejected'
                                                ? 'bg-red-50 text-red-700'
                                                : 'bg-gray-100 text-gray-600',
                                        )}
                                    >
                                        {entry.status.replace(/_/g, ' ')}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-sm text-gray-600">
                                        {entry.note ?? ''}
                                    </span>
                                    <span className="text-xs text-gray-400">{entry.at}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AdminLayout>
    );
}
