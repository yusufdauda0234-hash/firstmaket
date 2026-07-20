import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
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
                description="Pickups and deliveries assigned to you — move each order one step at a time; the customer is notified automatically."
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
            ) : (
                <div className="space-y-3">
                    {assignments.map((assignment) => (
                        <Card key={assignment.orderUuid} className="p-4">
                            <div className="flex flex-wrap items-center gap-4">
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
        </AdminLayout>
    );
}
