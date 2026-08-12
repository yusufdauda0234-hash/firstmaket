import { Badge } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination, PaginationLink } from '@/Components/ui/Pagination';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, Download, Receipt } from 'lucide-react';

interface Entry {
    kind: string;
    label: string;
    direction: 'in' | 'out';
    amountKobo: number;
    party: string | null;
    reference: string | null;
    occurredAt: string;
}

interface Props {
    entries: { data: Entry[]; links: PaginationLink[]; total: number };
    kinds: { value: string; label: string; direction: string }[];
    filters: { kind: string; direction: string; from: string; to: string };
    [key: string]: unknown;
}

/**
 * Every settled movement of money, in one list.
 *
 * There is no single transactions table behind this and there should not be —
 * a customer charge, a vendor payout and an office expense are genuinely
 * different records. This reads them together at query time, so nothing is
 * copied into a second place that could drift from the record it came from.
 */
export default function Transactions() {
    const { entries, kinds, filters } = usePage<Props>().props;

    const apply = (next: Partial<Props['filters']>) => {
        router.get(
            route('admin.transactions.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AdminLayout>
            <Head title="Transactions" />
            <PageHeader
                eyebrow="Finance"
                title="Transactions"
                description="Settled money only — a pending charge is not income, and a queued payout has not left the account."
                actions={
                    <Link
                        href={route('admin.transactions.export', { from: filters.from, to: filters.to })}
                        className="inline-flex items-center gap-1.5 rounded-full border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:border-brand-300 hover:text-brand-700"
                    >
                        <Download className="h-4 w-4" /> Export CSV
                    </Link>
                }
            />

            <Card className="mb-4 p-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">Type</label>
                        <Select value={filters.kind} onChange={(event) => apply({ kind: event.target.value })}>
                            <option value="">All types</option>
                            {kinds.map((kind) => (
                                <option key={kind.value} value={kind.value}>
                                    {kind.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">Direction</label>
                        <Select value={filters.direction} onChange={(event) => apply({ direction: event.target.value })}>
                            <option value="">In and out</option>
                            <option value="in">Money in</option>
                            <option value="out">Money out</option>
                        </Select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">From</label>
                        <Input type="date" value={filters.from} onChange={(event) => apply({ from: event.target.value })} />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">To</label>
                        <Input type="date" value={filters.to} onChange={(event) => apply({ to: event.target.value })} />
                    </div>
                </div>
            </Card>

            <Card className="overflow-hidden">
                {entries.data.length === 0 ? (
                    <div className="p-10 text-center">
                        <Receipt className="mx-auto h-10 w-10 text-gray-300" />
                        <p className="mt-3 text-sm text-gray-600">No settled movements in this period.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">When</th>
                                    <th className="px-4 py-3 font-semibold">Type</th>
                                    <th className="px-4 py-3 font-semibold">Who</th>
                                    <th className="px-4 py-3 font-semibold">Reference</th>
                                    <th className="px-4 py-3 text-right font-semibold">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {entries.data.map((entry) => (
                                    <tr key={`${entry.kind}-${entry.reference}-${entry.occurredAt}`} className="hover:bg-gray-50/60">
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-600">{entry.occurredAt}</td>
                                        <td className="whitespace-nowrap px-4 py-3">
                                            <Badge tone={entry.direction === 'in' ? 'success' : 'neutral'}>{entry.label}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-gray-900">{entry.party ?? '—'}</td>
                                        <td className="px-4 py-3 font-mono text-xs text-gray-400">{entry.reference ?? '—'}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right">
                                            {/* Sign and colour both, not colour
                                                alone — a ledger read in
                                                greyscale or by somebody
                                                colour-blind must still say
                                                which way the money went. */}
                                            <span
                                                className={`inline-flex items-center gap-1 font-medium tabular-nums ${
                                                    entry.direction === 'in' ? 'text-green-700' : 'text-gray-900'
                                                }`}
                                            >
                                                {entry.direction === 'in' ? (
                                                    <ArrowDownLeft className="h-3.5 w-3.5" />
                                                ) : (
                                                    <ArrowUpRight className="h-3.5 w-3.5" />
                                                )}
                                                {entry.direction === 'in' ? '+' : '−'}
                                                {formatNairaFromKobo(entry.amountKobo)}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Pagination links={entries.links} />
        </AdminLayout>
    );
}
