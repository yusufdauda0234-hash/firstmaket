import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, router } from '@inertiajs/react';
import { Banknote } from 'lucide-react';

interface PayoutItem {
    id: number;
    affiliate: string | null;
    amountKobo: number;
    status: string;
    rejectionReason: string | null;
    failureReason: string | null;
    reference: string | null;
    bank: string | null;
}

interface Batch {
    uuid: string;
    periodStart: string | null;
    periodEnd: string | null;
    status: string;
    totalKobo: number;
    thresholdKobo: number;
    approvedBy: string | null;
    approvedAt: string | null;
    items: PayoutItem[];
}

const STATUS_STYLES: Record<string, string> = {
    pending_approval: 'bg-amber-50 text-amber-700',
    approved: 'bg-brand-50 text-brand-700',
    processing: 'bg-brand-50 text-brand-700',
    completed: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-red-50 text-red-700',
};

const ask = (message: string) => {
    const answer = window.prompt(message);

    return answer && answer.trim() !== '' ? answer.trim() : null;
};

export default function Payouts({ batches = [], minimumThresholdKobo }: { batches: Batch[]; minimumThresholdKobo: number }) {
    return (
        <AdminLayout>
            <Head title="Affiliate payouts" />
            <PageHeader
                title="Affiliate payouts"
                description={`Monthly partner transfers. Only qualified conversions count, and only partners over ${formatNairaFromKobo(minimumThresholdKobo)} with a verified account are included.`}
                actions={
                    <Button
                        onClick={() => {
                            if (!confirm('Generate a payout batch for every eligible partner?')) return;
                            router.post(route('admin.affiliates.payouts.generate'), {}, { preserveScroll: true });
                        }}
                    >
                        Generate batch
                    </Button>
                }
            />

            {batches.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Banknote className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No payout batches yet</p>
                    <p className="mt-1 max-w-md text-sm text-gray-500">
                        Generating a batch gathers every partner's qualified commissions. Nothing is sent until the
                        batch is approved.
                    </p>
                </Card>
            ) : (
                <div className="space-y-4">
                    {batches.map((batch) => (
                        <Card key={batch.uuid} className="!p-0 overflow-hidden">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3.5">
                                <div>
                                    <h2 className="font-bold text-gray-900">
                                        {batch.periodStart} – {batch.periodEnd}
                                    </h2>
                                    <p className="text-xs text-gray-500">
                                        {formatNairaFromKobo(batch.totalKobo)} across {batch.items.length} partner(s) ·
                                        threshold {formatNairaFromKobo(batch.thresholdKobo)}
                                        {batch.approvedBy && ` · approved by ${batch.approvedBy}`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className={`rounded-full px-3 py-1 text-xs font-bold ${STATUS_STYLES[batch.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                        {batch.status.replace(/_/g, ' ')}
                                    </span>
                                    {batch.status === 'pending_approval' && (
                                        <Button
                                            onClick={() => {
                                                if (!confirm(`Approve ${formatNairaFromKobo(batch.totalKobo)} of partner payouts?`)) return;
                                                router.post(route('admin.affiliates.payouts.approve', batch.uuid), {}, { preserveScroll: true });
                                            }}
                                        >
                                            Approve batch
                                        </Button>
                                    )}
                                </div>
                            </div>

                            <div className="divide-y divide-gray-50">
                                {batch.items.map((item) => (
                                    <div key={item.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-bold text-gray-900">{item.affiliate}</p>
                                            <p className="text-xs text-gray-500">{item.bank ?? 'No verified account'}</p>
                                            {(item.rejectionReason || item.failureReason) && (
                                                <p className="text-xs text-red-600">{item.rejectionReason ?? item.failureReason}</p>
                                            )}
                                            {item.reference && <p className="text-xs text-gray-400">Ref {item.reference}</p>}
                                        </div>
                                        <p className="font-mono text-sm font-bold text-gray-900">{formatNairaFromKobo(item.amountKobo)}</p>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <span className="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-bold capitalize text-gray-600">
                                                {item.status}
                                            </span>
                                            {item.status === 'approved' && (
                                                <>
                                                    <button
                                                        onClick={() => {
                                                            const reference = ask('Paystack transfer reference:');
                                                            if (reference) {
                                                                router.post(route('admin.affiliates.payouts.items.paid', item.id), { reference }, { preserveScroll: true });
                                                            }
                                                        }}
                                                        className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white"
                                                    >
                                                        Mark paid
                                                    </button>
                                                    <button
                                                        onClick={() => {
                                                            const reason = ask('Why did the transfer fail?');
                                                            if (reason) {
                                                                router.post(route('admin.affiliates.payouts.items.failed', item.id), { reason }, { preserveScroll: true });
                                                            }
                                                        }}
                                                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600"
                                                    >
                                                        Failed
                                                    </button>
                                                </>
                                            )}
                                            {(item.status === 'pending' || item.status === 'approved') && (
                                                <button
                                                    onClick={() => {
                                                        const reason = ask('Why is this payout line being rejected?');
                                                        if (reason) {
                                                            router.post(route('admin.affiliates.payouts.items.reject', item.id), { reason }, { preserveScroll: true });
                                                        }
                                                    }}
                                                    className="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white"
                                                >
                                                    Reject
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </AdminLayout>
    );
}
