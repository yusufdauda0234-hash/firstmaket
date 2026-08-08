import BulkActionBar from '@/Components/ui/BulkActionBar';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import ViewToggle from '@/Components/ui/ViewToggle';
import { useRowSelection } from '@/Hooks/useRowSelection';
import { useViewMode } from '@/Hooks/useViewMode';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/Utils/cn';
import { Head, useForm, usePage } from '@inertiajs/react';
import { MapPin, PackageCheck, Store, Truck } from 'lucide-react';

interface AssignmentRow {
    orderUuid: string;
    productName: string;
    vendorName: string;
    pickupFrom: string;
    deliverTo: string;
    address: string;
    status: string;
    statusLabel: string;
    nextStep: string | null;
    nextStepLabel: string | null;
    assignedAt: string;
}

interface Props {
    assignments: AssignmentRow[];
    [key: string]: unknown;
}

const statusStyle: Record<string, string> = {
    ready_for_pickup: 'bg-indigo-50 text-indigo-700',
    packed: 'bg-violet-50 text-violet-700',
    shipped: 'bg-sky-50 text-sky-700',
    out_for_delivery: 'bg-amber-50 text-amber-700',
    processing: 'bg-gray-100 text-gray-600',
};

export default function LogisticsOrders() {
    const { assignments } = usePage<Props>().props;
    const form = useForm({ status: '' });
    const { mode, choose } = useViewMode('admin.deliveries', 'table');

    // Only a stop with somewhere left to go is selectable.
    const advanceable = assignments.filter((a) => a.nextStep !== null);
    const selection = useRowSelection(advanceable.map((a) => a.orderUuid));
    const bulk = useForm<{ uuids: string[] }>({ uuids: [] });

    function advanceSelected() {
        bulk.transform(() => ({ uuids: selection.ids }));
        bulk.post(route('admin.deliveries.bulk-advance'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    const advance = (assignment: AssignmentRow) => {
        if (!assignment.nextStep) return;
        form.transform(() => ({ status: assignment.nextStep }));
        form.post(route('admin.deliveries.update-status', assignment.orderUuid), { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="My deliveries" />

            <PageHeader
                eyebrow="Logistics"
                title="My deliveries"
                description="Pickups and deliveries assigned to you. Each order moves one step at a time and the customer is notified automatically."
                actions={<ViewToggle mode={mode} onChange={choose} label="deliveries" />}
            />

            {assignments.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Truck className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No active assignments</p>
                    <p className="mt-1 max-w-sm text-sm text-gray-500">
                        Orders assigned to you for pickup or delivery will appear here.
                    </p>
                </Card>
            ) : mode === 'table' ? (
                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[860px] text-sm">
                            <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="w-10 py-3 pl-5 pr-2">
                                        <RowCheckbox
                                            checked={selection.allSelected}
                                            indeterminate={selection.someSelected}
                                            onChange={selection.toggleAll}
                                            label="Select all deliveries that can move on"
                                        />
                                    </th>
                                    <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                    <th className="px-5 py-3 font-semibold">Order</th>
                                    <th className="px-4 py-3 font-semibold">Pickup</th>
                                    <th className="px-4 py-3 font-semibold">Deliver to</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-5 py-3 text-right font-semibold">Next step</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {assignments.map((assignment, index) => (
                                    <tr
                                        key={assignment.orderUuid}
                                        className={cn(
                                            'transition-colors hover:bg-brand-50/40',
                                            selection.isSelected(assignment.orderUuid) && 'bg-brand-50/70',
                                        )}
                                    >
                                        <td className="py-3.5 pl-5 pr-2">
                                            {assignment.nextStep && (
                                                <RowCheckbox
                                                    checked={selection.isSelected(assignment.orderUuid)}
                                                    onChange={() => selection.toggle(assignment.orderUuid)}
                                                    label={`Select ${assignment.productName}`}
                                                />
                                            )}
                                        </td>
                                        <td className="px-2 py-3.5 text-xs tabular-nums text-gray-400">
                                            {index + 1}
                                        </td>
                                        <td className="px-5 py-3.5">
                                            <span className="line-clamp-1 font-semibold text-gray-900">
                                                {assignment.productName}
                                            </span>
                                            <span className="mt-0.5 block font-mono text-xs text-gray-400">
                                                {assignment.orderUuid.slice(0, 8).toUpperCase()} ·{' '}
                                                {assignment.assignedAt}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-gray-600">{assignment.pickupFrom}</td>
                                        <td className="px-4 py-3.5 text-gray-600">
                                            {assignment.deliverTo}
                                            <span className="block text-xs text-gray-400">
                                                {assignment.address}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span
                                                className={cn(
                                                    'rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                                    statusStyle[assignment.status] ?? 'bg-gray-100 text-gray-500',
                                                )}
                                            >
                                                {assignment.statusLabel}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3.5 text-right">
                                            {assignment.nextStep ? (
                                                <button
                                                    type="button"
                                                    disabled={form.processing}
                                                    onClick={() => advance(assignment)}
                                                    className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-3.5 py-1.5 text-xs font-bold text-white transition hover:bg-brand-700 disabled:opacity-60"
                                                >
                                                    <PackageCheck className="h-3.5 w-3.5" />
                                                    {assignment.nextStepLabel}
                                                </button>
                                            ) : (
                                                <span className="text-xs text-gray-300">done</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            ) : (
                <div className="space-y-3">
                    {assignments.map((assignment) => (
                        <Card key={assignment.orderUuid} className="p-4">
                            <div className="flex flex-wrap items-center gap-4">
                                {assignment.nextStep && (
                                    <RowCheckbox
                                        checked={selection.isSelected(assignment.orderUuid)}
                                        onChange={() => selection.toggle(assignment.orderUuid)}
                                        label={`Select ${assignment.productName}`}
                                    />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-bold text-gray-900">
                                        {assignment.productName}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        Order {assignment.orderUuid.slice(0, 8).toUpperCase()} · assigned{' '}
                                        {assignment.assignedAt}
                                    </p>
                                    <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
                                        <span className="inline-flex items-center gap-1">
                                            <Store className="h-3.5 w-3.5 text-gray-400" /> Pickup:{' '}
                                            {assignment.pickupFrom}
                                        </span>
                                        <span className="inline-flex items-center gap-1">
                                            <MapPin className="h-3.5 w-3.5 text-gray-400" /> {assignment.address},{' '}
                                            {assignment.deliverTo}
                                        </span>
                                    </div>
                                </div>
                                <span
                                    className={cn(
                                        'rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                        statusStyle[assignment.status] ?? 'bg-gray-100 text-gray-500',
                                    )}
                                >
                                    {assignment.statusLabel}
                                </span>
                                {assignment.nextStep && (
                                    <button
                                        type="button"
                                        disabled={form.processing}
                                        onClick={() => advance(assignment)}
                                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                                    >
                                        <PackageCheck className="h-4 w-4" /> Mark {assignment.nextStepLabel}
                                    </button>
                                )}
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <BulkActionBar
                count={selection.count}
                noun="delivery"
                plural="deliveries"
                processing={bulk.processing}
                onClear={selection.clear}
                // Each moves to its own next step, so a mixed list advances
                // correctly rather than being forced to one shared status.
                actions={[{ label: 'Move on', tone: 'primary', run: advanceSelected }]}
            />
        </AdminLayout>
    );
}
