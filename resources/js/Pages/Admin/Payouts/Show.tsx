import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, XCircle } from 'lucide-react';
import { useState } from 'react';

interface ItemRow {
    id: number;
    vendorName: string;
    bankName: string | null;
    accountName: string;
    amountKobo: number;
    status: string;
    reference: string | null;
    failureReason: string | null;
    paidAt: string | null;
}

interface Props {
    batch: {
        uuid: string;
        periodStart: string;
        periodEnd: string;
        status: string;
        totalKobo: number;
        approvedAt: string | null;
        items: ItemRow[];
    };
    [key: string]: unknown;
}

const itemStatusStyle: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-600',
    approved: 'bg-sky-50 text-sky-700',
    paid: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-red-50 text-red-700',
    rejected: 'bg-red-50 text-red-700',
};

export default function PayoutBatchShow() {
    const { batch } = usePage<Props>().props;
    const approveForm = useForm({});
    const paidForm = useForm({ transfer_reference: '' });
    const failedForm = useForm({ reason: '' });
    const [acting, setActing] = useState<{ id: number; mode: 'paid' | 'failed' } | null>(null);

    return (
        <AdminLayout>
            <Head title={`Payout batch ${batch.uuid.slice(0, 8).toUpperCase()}`} />

            <Link
                href={route('admin.payouts.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> All batches
            </Link>

            <PageHeader
                eyebrow={`Batch ${batch.uuid.slice(0, 8).toUpperCase()}`}
                title={`${batch.periodStart} → ${batch.periodEnd}`}
                description={`Total ${formatNairaFromKobo(batch.totalKobo)} across ${batch.items.length} vendor(s).`}
                actions={
                    batch.status === 'pending_approval' ? (
                        <button
                            type="button"
                            disabled={approveForm.processing}
                            onClick={() =>
                                approveForm.post(route('admin.payouts.approve', batch.uuid), {
                                    preserveScroll: true,
                                })
                            }
                            className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                        >
                            <CheckCircle2 className="h-4 w-4" /> Approve batch
                        </button>
                    ) : (
                        <span className="rounded-full bg-brand-50 px-3 py-1.5 text-xs font-bold uppercase text-brand-700">
                            {batch.status.replace(/_/g, ' ')}
                        </span>
                    )
                }
            />

            <Card className="overflow-hidden p-0">
                {batch.items.length === 0 ? (
                    <p className="px-6 py-14 text-center text-sm text-gray-500">
                        No payable vendors in this batch — vendors need cleared earnings and a verified bank
                        account.
                    </p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {batch.items.map((item) => (
                            <li key={item.id} className="px-5 py-4">
                                <div className="flex flex-wrap items-center gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-bold text-gray-900">{item.vendorName}</p>
                                        <p className="mt-0.5 text-xs text-gray-400">
                                            {item.accountName} · {item.bankName}
                                            {item.reference && ` · ${item.reference}`}
                                            {item.failureReason && (
                                                <span className="text-red-500"> · {item.failureReason}</span>
                                            )}
                                        </p>
                                    </div>
                                    <span className="text-sm font-bold text-gray-900">
                                        {formatNairaFromKobo(item.amountKobo)}
                                    </span>
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase',
                                            itemStatusStyle[item.status] ?? 'bg-gray-100 text-gray-500',
                                        )}
                                    >
                                        {item.status}
                                    </span>
                                    {item.status === 'approved' && (
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setActing(
                                                        acting?.id === item.id && acting.mode === 'paid'
                                                            ? null
                                                            : { id: item.id, mode: 'paid' },
                                                    )
                                                }
                                                className="inline-flex items-center gap-1 rounded-full border border-emerald-200 px-3.5 py-1.5 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-50 active:scale-95"
                                            >
                                                <CheckCircle2 className="h-4 w-4" /> Paid
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setActing(
                                                        acting?.id === item.id && acting.mode === 'failed'
                                                            ? null
                                                            : { id: item.id, mode: 'failed' },
                                                    )
                                                }
                                                className="inline-flex items-center gap-1 rounded-full border border-red-200 px-3.5 py-1.5 text-sm font-semibold text-red-600 transition hover:bg-red-50 active:scale-95"
                                            >
                                                <XCircle className="h-4 w-4" /> Failed
                                            </button>
                                        </div>
                                    )}
                                </div>

                                {acting?.id === item.id && acting.mode === 'paid' && (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            paidForm.post(route('admin.payouts.items.paid', item.id), {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    setActing(null);
                                                    paidForm.reset();
                                                },
                                            });
                                        }}
                                        className="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-emerald-50 p-3"
                                    >
                                        <input
                                            type="text"
                                            placeholder="Paystack transfer reference"
                                            value={paidForm.data.transfer_reference}
                                            onChange={(e) => paidForm.setData('transfer_reference', e.target.value)}
                                            required
                                            autoFocus
                                            className="min-w-[240px] flex-1 rounded-full border-emerald-200 text-sm focus:border-emerald-400 focus:ring-emerald-400/20"
                                        />
                                        <button
                                            type="submit"
                                            disabled={paidForm.processing}
                                            className="rounded-full bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-700 active:scale-95 disabled:opacity-60"
                                        >
                                            Record payment
                                        </button>
                                        <InputError message={paidForm.errors.transfer_reference} />
                                    </form>
                                )}

                                {acting?.id === item.id && acting.mode === 'failed' && (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            failedForm.post(route('admin.payouts.items.failed', item.id), {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    setActing(null);
                                                    failedForm.reset();
                                                },
                                            });
                                        }}
                                        className="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-red-50 p-3"
                                    >
                                        <input
                                            type="text"
                                            placeholder="Failure reason (e.g. transfer bounced)"
                                            value={failedForm.data.reason}
                                            onChange={(e) => failedForm.setData('reason', e.target.value)}
                                            required
                                            autoFocus
                                            className="min-w-[240px] flex-1 rounded-full border-red-200 text-sm focus:border-red-400 focus:ring-red-400/20"
                                        />
                                        <button
                                            type="submit"
                                            disabled={failedForm.processing}
                                            className="rounded-full bg-red-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-700 active:scale-95 disabled:opacity-60"
                                        >
                                            Mark failed
                                        </button>
                                        <InputError message={failedForm.errors.reason} />
                                    </form>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </AdminLayout>
    );
}
