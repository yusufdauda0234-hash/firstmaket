import { Badge } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head } from '@inertiajs/react';

interface Item {
    id: number;
    reference: string;
    providerAmountKobo: number | null;
    ledgerAmountKobo: number | null;
    status: string;
}

interface Props {
    import: { id: number; provider: string; status: string; importedBy: string | null; completedAt: string | null };
    items: Item[];
    summary: {
        matched: number;
        missing_in_ledger: number;
        missing_in_provider: number;
        amount_mismatch: number;
    };
    [key: string]: unknown;
}

const statusMeta: Record<string, { label: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
    matched: { label: 'Matched', tone: 'success' },
    missing_in_ledger: { label: 'Missing in ledger', tone: 'danger' },
    missing_in_provider: { label: 'Missing in provider', tone: 'warning' },
    amount_mismatch: { label: 'Amount mismatch', tone: 'danger' },
};

export default function ReconciliationShow({ import: batch, items, summary }: Props) {
    const cards = [
        { key: 'matched', label: 'Matched', value: summary.matched, accent: 'text-emerald-600' },
        { key: 'amount_mismatch', label: 'Amount mismatch', value: summary.amount_mismatch, accent: 'text-red-600' },
        { key: 'missing_in_ledger', label: 'Missing in ledger', value: summary.missing_in_ledger, accent: 'text-red-600' },
        { key: 'missing_in_provider', label: 'Missing in provider', value: summary.missing_in_provider, accent: 'text-amber-600' },
    ];

    return (
        <AdminLayout>
            <Head title={`Settlement #${batch.id}`} />

            <PageHeader
                eyebrow="Finance"
                title={`Settlement #${batch.id}`}
                description={`${batch.provider} · imported by ${batch.importedBy ?? 'system'}${batch.completedAt ? ` · ${batch.completedAt}` : ''}`}
                backHref={route('admin.reconciliation.index')}
                backLabel="Back to reconciliation"
            />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                {cards.map((card, i) => (
                    <Reveal key={card.key} delay={i * 80}>
                        <Card>
                            <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">{card.label}</p>
                            <p className={`mt-2 text-3xl font-extrabold tabular-nums ${card.accent}`}>{card.value}</p>
                        </Card>
                    </Reveal>
                ))}
            </div>

            <Card className="mt-6 overflow-x-auto p-0">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead>
                        <tr className="text-left text-gray-500">
                            <th className="px-5 py-3 font-medium">Reference</th>
                            <th className="px-5 py-3 font-medium">Provider amount</th>
                            <th className="px-5 py-3 font-medium">Ledger amount</th>
                            <th className="px-5 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {items.map((item) => {
                            const meta = statusMeta[item.status] ?? { label: item.status, tone: 'neutral' as const };
                            return (
                                <tr key={item.id} className={item.status !== 'matched' ? 'bg-red-50/30' : ''}>
                                    <td className="px-5 py-3 font-mono text-xs text-gray-700">{item.reference}</td>
                                    <td className="px-5 py-3">
                                        {item.providerAmountKobo != null ? formatNairaFromKobo(item.providerAmountKobo) : '—'}
                                    </td>
                                    <td className="px-5 py-3">
                                        {item.ledgerAmountKobo != null ? formatNairaFromKobo(item.ledgerAmountKobo) : '—'}
                                    </td>
                                    <td className="px-5 py-3">
                                        <Badge light tone={meta.tone}>{meta.label}</Badge>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </Card>
        </AdminLayout>
    );
}
