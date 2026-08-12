import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Percent, TrendingDown, TrendingUp, Wallet } from 'lucide-react';

interface KindTotal {
    kind: string;
    label: string;
    direction: string;
    totalKobo: number;
    count: number;
}

interface Props {
    summary: {
        inKobo: number;
        outKobo: number;
        netKobo: number;
        commissionKobo: number;
        byKind: KindTotal[];
        byMonth: { month: string; inKobo: number; outKobo: number }[];
    };
    expensesByCategory: { category: string; label: string; totalKobo: number; count: number }[];
    filters: { from: string; to: string };
    [key: string]: unknown;
}

/**
 * The business at a glance.
 *
 * Commission is shown separately from money in, never added to it: it is the
 * platform's share of a customer charge that already appears above, and
 * counting it twice would make the marketplace look twice as good as it is.
 */
export default function Summary() {
    const { summary, expensesByCategory, filters } = usePage<Props>().props;

    const apply = (next: Partial<Props['filters']>) => {
        router.get(route('admin.finance.summary'), { ...filters, ...next }, { preserveState: true, replace: true });
    };

    const peak = Math.max(1, ...summary.byMonth.flatMap((month) => [month.inKobo, month.outKobo]));
    const moneyIn = summary.byKind.filter((kind) => kind.direction === 'in');
    const moneyOut = summary.byKind.filter((kind) => kind.direction === 'out');

    return (
        <AdminLayout>
            <Head title="Financial summary" />
            <PageHeader
                eyebrow="Finance"
                title="Financial summary"
                description="What came in, what went out, and what the platform is left holding."
                actions={
                    <Link
                        href={route('admin.transactions.index', filters)}
                        className="inline-flex items-center gap-1.5 rounded-full border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:border-brand-300 hover:text-brand-700"
                    >
                        See every transaction <ArrowRight className="h-4 w-4" />
                    </Link>
                }
            />

            <Card className="mb-6 flex flex-wrap items-end gap-3 p-4">
                <div>
                    <label className="mb-1 block text-xs font-medium text-gray-600">From</label>
                    <Input type="date" value={filters.from} onChange={(event) => apply({ from: event.target.value })} />
                </div>
                <div>
                    <label className="mb-1 block text-xs font-medium text-gray-600">To</label>
                    <Input type="date" value={filters.to} onChange={(event) => apply({ to: event.target.value })} />
                </div>
            </Card>

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Headline
                    label="Money in"
                    value={formatNairaFromKobo(summary.inKobo)}
                    icon={TrendingUp}
                    tone="text-green-700"
                    hint="Verified customer payments"
                />
                <Headline
                    label="Money out"
                    value={formatNairaFromKobo(summary.outKobo)}
                    icon={TrendingDown}
                    tone="text-red-700"
                    hint="Payouts, refunds and expenses"
                />
                <Headline
                    label="Net position"
                    value={formatNairaFromKobo(summary.netKobo)}
                    icon={Wallet}
                    tone={summary.netKobo < 0 ? 'text-red-700' : 'text-gray-900'}
                    hint="Held, not earned — some is already owed"
                />
                <Headline
                    label="Commission earned"
                    value={formatNairaFromKobo(summary.commissionKobo)}
                    icon={Percent}
                    tone="text-brand-700"
                    hint="The platform's share, net of promo discounts"
                />
            </div>

            {summary.byMonth.length > 0 && (
                <Card className="mb-6 p-5">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Month by month</h2>
                    <div className="mt-4 flex h-40 items-end gap-2 overflow-x-auto">
                        {summary.byMonth.map((month) => (
                            <div key={month.month} className="flex min-w-[36px] flex-1 flex-col items-center gap-1">
                                <div className="flex h-32 w-full items-end justify-center gap-0.5">
                                    <div
                                        className="w-1/2 rounded-t bg-green-500/80"
                                        style={{ height: `${Math.max(2, (month.inKobo / peak) * 100)}%` }}
                                        title={`In: ${formatNairaFromKobo(month.inKobo)}`}
                                    />
                                    <div
                                        className="w-1/2 rounded-t bg-red-400/80"
                                        style={{ height: `${Math.max(2, (month.outKobo / peak) * 100)}%` }}
                                        title={`Out: ${formatNairaFromKobo(month.outKobo)}`}
                                    />
                                </div>
                                <span className="text-[10px] tabular-nums text-gray-400">{month.month.slice(5)}</span>
                            </div>
                        ))}
                    </div>
                    <p className="mt-2 flex items-center gap-4 text-xs text-gray-500">
                        <span className="flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-green-500" /> In
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-red-400" /> Out
                        </span>
                    </p>
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <Breakdown title="Money in" rows={moneyIn} total={summary.inKobo} barClass="bg-green-500" />
                <Breakdown title="Money out" rows={moneyOut} total={summary.outKobo} barClass="bg-red-400" />

                <Card className="p-5">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Expenses by category</h2>
                    {expensesByCategory.length === 0 ? (
                        <p className="mt-4 text-sm text-gray-500">No expenses recorded in this period.</p>
                    ) : (
                        <ul className="mt-4 space-y-2">
                            {expensesByCategory.map((row) => (
                                <li key={row.category} className="flex items-baseline justify-between text-sm">
                                    <span className="text-gray-700">{row.label}</span>
                                    <span className="font-medium tabular-nums text-gray-900">
                                        {formatNairaFromKobo(row.totalKobo)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Link
                        href={route('admin.expenses.index', filters)}
                        className="mt-4 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline"
                    >
                        Manage expenses <ArrowRight className="h-3 w-3" />
                    </Link>
                </Card>
            </div>
        </AdminLayout>
    );
}

function Headline({
    label,
    value,
    icon: Icon,
    tone,
    hint,
}: {
    label: string;
    value: string;
    icon: React.ComponentType<{ className?: string }>;
    tone: string;
    hint: string;
}) {
    return (
        <Card className="p-4">
            <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500">
                <Icon className="h-3.5 w-3.5" /> {label}
            </p>
            <p className={`mt-1 text-2xl font-bold tabular-nums ${tone}`}>{value}</p>
            <p className="mt-0.5 text-xs text-gray-400">{hint}</p>
        </Card>
    );
}

function Breakdown({
    title,
    rows,
    total,
    barClass,
}: {
    title: string;
    rows: KindTotal[];
    total: number;
    barClass: string;
}) {
    return (
        <Card className="p-5">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">{title}</h2>
            {rows.length === 0 ? (
                <p className="mt-4 text-sm text-gray-500">Nothing in this period.</p>
            ) : (
                <ul className="mt-4 space-y-3">
                    {rows.map((row) => (
                        <li key={row.kind}>
                            <div className="flex items-baseline justify-between text-sm">
                                <span className="text-gray-700">
                                    {row.label}{' '}
                                    <span className="text-xs text-gray-400">({row.count.toLocaleString()})</span>
                                </span>
                                <span className="font-medium tabular-nums text-gray-900">
                                    {formatNairaFromKobo(row.totalKobo)}
                                </span>
                            </div>
                            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100">
                                <div
                                    className={`h-full rounded-full ${barClass}`}
                                    style={{ width: `${Math.round((row.totalKobo / Math.max(1, total)) * 100)}%` }}
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
