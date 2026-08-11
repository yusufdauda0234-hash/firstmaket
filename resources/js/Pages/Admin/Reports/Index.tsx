import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, router, usePage } from '@inertiajs/react';
import { Banknote, CalendarClock, ClipboardList, Download, Heart, PackageCheck, Store, UserPlus, Wallet } from 'lucide-react';
import { ComponentType, FormEventHandler, useState } from 'react';

interface Props {
    from: string;
    to: string;
    signups: { total: number; customers: number; vendors: number };
    deposits: { count: number; totalKobo: number };
    planCompletions: { count: number };
    orderVolume: { count: number; totalKobo: number; byStatus: Record<string, number> };
    vendorActivity: { newVendors: number; approvedProducts: number };
    productApprovalOutcomes: { approved: number; rejected: number };
    /** Phase 2D forecasting — aggregates only, never customer identity. */
    wishlistDemand: { count: number; rows: { shortfall: number }[] };
    expectedCompletions: { count: number; totalRemainingKobo: number };
    vendorPerformance: { count: number; rows: { score: number }[] };
    [key: string]: unknown;
}

interface ReportCardProps {
    title: string;
    icon: ComponentType<{ className?: string }>;
    stats: { label: string; value: string }[];
    exportKey: string;
    from: string;
    to: string;
}

function ReportCard({ title, icon: Icon, stats, exportKey, from, to }: ReportCardProps) {
    return (
        <Card>
            <div className="flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-sm font-bold text-gray-900">
                    <Icon className="h-4 w-4 text-brand-600" /> {title}
                </h2>
                <a
                    href={route('admin.reports.export', { report: exportKey, from, to })}
                    className="flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline"
                >
                    <Download className="h-3.5 w-3.5" /> CSV
                </a>
            </div>
            <dl className="mt-3 space-y-1.5">
                {stats.map((stat) => (
                    <div key={stat.label} className="flex items-center justify-between text-sm">
                        <dt className="text-gray-500">{stat.label}</dt>
                        <dd className="font-bold text-gray-900">{stat.value}</dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

export default function ReportsIndex() {
    const {
        from,
        to,
        signups,
        deposits,
        planCompletions,
        orderVolume,
        vendorActivity,
        productApprovalOutcomes,
        wishlistDemand,
        expectedCompletions,
        vendorPerformance,
    } = usePage<Props>().props;

    const [fromDate, setFromDate] = useState(from);
    const [toDate, setToDate] = useState(to);

    const submitRange: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.reports.index'), { from: fromDate, to: toDate }, { preserveState: true });
    };

    return (
        <AdminLayout>
            <Head title="Reports" />

            <PageHeader
                eyebrow="Operational controls"
                title="Reports"
                description="Every figure is a live read from its source table — nothing here is cached."
            />

            <form onSubmit={submitRange} className="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label className="block text-xs font-semibold text-gray-500">From</label>
                    <input
                        type="date"
                        value={fromDate}
                        onChange={(e) => setFromDate(e.target.value)}
                        className="border mt-1 rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500/20 px-3 py-2 shadow-sm"
                    />
                </div>
                <div>
                    <label className="block text-xs font-semibold text-gray-500">To</label>
                    <input
                        type="date"
                        value={toDate}
                        onChange={(e) => setToDate(e.target.value)}
                        className="border mt-1 rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500/20 px-3 py-2 shadow-sm"
                    />
                </div>
                <button
                    type="submit"
                    className="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                >
                    Apply range
                </button>
            </form>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <ReportCard
                    title="Signups"
                    icon={UserPlus}
                    exportKey="signups"
                    from={from}
                    to={to}
                    stats={[
                        { label: 'Total', value: String(signups.total) },
                        { label: 'Customers', value: String(signups.customers) },
                        { label: 'Vendors', value: String(signups.vendors) },
                    ]}
                />
                <ReportCard
                    title="Deposits"
                    icon={Wallet}
                    exportKey="deposits"
                    from={from}
                    to={to}
                    stats={[
                        { label: 'Count', value: String(deposits.count) },
                        { label: 'Total', value: formatNairaFromKobo(deposits.totalKobo) },
                    ]}
                />
                <ReportCard
                    title="Plan completions"
                    icon={PackageCheck}
                    exportKey="plan-completions"
                    from={from}
                    to={to}
                    stats={[{ label: 'Completed', value: String(planCompletions.count) }]}
                />
                <ReportCard
                    title="Order volume"
                    icon={ClipboardList}
                    exportKey="order-volume"
                    from={from}
                    to={to}
                    stats={[
                        { label: 'Orders', value: String(orderVolume.count) },
                        { label: 'Total value', value: formatNairaFromKobo(orderVolume.totalKobo) },
                        ...Object.entries(orderVolume.byStatus).map(([status, count]) => ({
                            label: status.replace('_', ' '),
                            value: String(count),
                        })),
                    ]}
                />
                <ReportCard
                    title="Vendor activity"
                    icon={Store}
                    exportKey="vendor-activity"
                    from={from}
                    to={to}
                    stats={[
                        { label: 'New vendors', value: String(vendorActivity.newVendors) },
                        { label: 'Products approved', value: String(vendorActivity.approvedProducts) },
                    ]}
                />
                <ReportCard
                    title="Product approval outcomes"
                    icon={Banknote}
                    exportKey="product-approvals"
                    from={from}
                    to={to}
                    stats={[
                        { label: 'Approved', value: String(productApprovalOutcomes.approved) },
                        { label: 'Rejected', value: String(productApprovalOutcomes.rejected) },
                    ]}
                />
            </div>

            {/*
                Forecasting looks forward, so it sits apart from the reports
                above and deliberately ignores the date range — a demand
                signal filtered to last month is not a forecast.

                Every figure here is an aggregate. Phase 2D requires that
                forecasting never expose customer identity, and the safest way
                to hold that line is for the identity never to be in the data.
            */}
            <h2 className="mb-3 mt-8 text-sm font-extrabold uppercase tracking-wide text-gray-500">
                Looking ahead
            </h2>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <ReportCard
                    title="Wishlist demand"
                    icon={Heart}
                    exportKey="wishlist-demand"
                    from={from}
                    to={to}
                    stats={[
                        { label: 'Products wanted', value: String(wishlistDemand.count) },
                        {
                            label: 'Short of stock',
                            value: String(wishlistDemand.rows.filter((row) => row.shortfall > 0).length),
                        },
                    ]}
                />
                <ReportCard
                    title="Expected completions"
                    icon={CalendarClock}
                    exportKey="expected-completions"
                    from={from}
                    to={to}
                    stats={[
                        { label: 'Plans due', value: String(expectedCompletions.count) },
                        {
                            label: 'Still to be paid',
                            value: formatNairaFromKobo(expectedCompletions.totalRemainingKobo),
                        },
                    ]}
                />
                <ReportCard
                    title="Vendor performance"
                    icon={Store}
                    exportKey="vendor-performance"
                    from={from}
                    to={to}
                    stats={[
                        { label: 'Rated vendors', value: String(vendorPerformance.count) },
                        {
                            label: 'Top score',
                            value: String(vendorPerformance.rows[0]?.score ?? 0),
                        },
                    ]}
                />
            </div>
        </AdminLayout>
    );
}
