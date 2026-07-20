import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination } from '@/Components/ui/Pagination';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Banknote, ChevronRight, Plus } from 'lucide-react';

interface BatchRow {
    uuid: string;
    periodStart: string;
    periodEnd: string;
    status: string;
    totalKobo: number;
    itemCount: number;
    createdAt: string;
}

interface Props {
    batches: {
        data: BatchRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    [key: string]: unknown;
}

const batchStatusStyle: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-600',
    pending_approval: 'bg-amber-50 text-amber-700',
    approved: 'bg-sky-50 text-sky-700',
    processing: 'bg-violet-50 text-violet-700',
    completed: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-red-50 text-red-700',
};

export default function PayoutBatches() {
    const { batches } = usePage<Props>().props;
    const generateForm = useForm({});

    return (
        <AdminLayout>
            <Head title="Vendor payouts" />

            <PageHeader
                eyebrow="Finance"
                title="Vendor payouts"
                description="Weekly batches of cleared vendor earnings — generate, approve, and record each bank transfer."
                actions={
                    <button
                        type="button"
                        disabled={generateForm.processing}
                        onClick={() => generateForm.post(route('admin.payouts.generate'))}
                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                    >
                        <Plus className="h-4 w-4" />
                        {generateForm.processing ? 'Generating…' : 'Generate batch'}
                    </button>
                }
            />

            <Card className="overflow-hidden p-0">
                {batches.data.length === 0 ? (
                    <div className="flex flex-col items-center px-6 py-14 text-center">
                        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                            <Banknote className="h-7 w-7" />
                        </span>
                        <p className="mt-4 text-sm font-medium text-gray-900">No payout batches yet</p>
                        <p className="mt-1 max-w-sm text-sm text-gray-500">
                            Generate a batch to sweep every vendor's cleared earnings into a payable list.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {batches.data.map((batch) => (
                            <li key={batch.uuid}>
                                <Link
                                    href={route('admin.payouts.show', batch.uuid)}
                                    className="flex items-center gap-4 px-5 py-4 transition hover:bg-slate-50"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-bold text-gray-900">
                                            {batch.periodStart} → {batch.periodEnd}
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-400">
                                            {batch.uuid.slice(0, 8).toUpperCase()} · {batch.itemCount} vendor(s) ·
                                            created {batch.createdAt}
                                        </p>
                                    </div>
                                    <span className="text-sm font-bold text-gray-900">
                                        {formatNairaFromKobo(batch.totalKobo)}
                                    </span>
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase',
                                            batchStatusStyle[batch.status] ?? 'bg-gray-100 text-gray-500',
                                        )}
                                    >
                                        {batch.status.replace(/_/g, ' ')}
                                    </span>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-gray-300" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Pagination links={batches.links} />
        </AdminLayout>
    );
}
