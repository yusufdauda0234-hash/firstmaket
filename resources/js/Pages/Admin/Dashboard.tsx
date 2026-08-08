import { Card } from '@/Components/ui/Card';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Check, CheckCircle2, Rocket } from 'lucide-react';

interface Queue {
    key: string;
    label: string;
    count: number;
    href: string;
    tone: 'brand' | 'emerald' | 'amber' | 'red' | 'violet' | 'slate';
    urgent?: boolean;
}

interface SetupStep {
    label: string;
    why: string;
    done: boolean;
    href: string;
}

interface Figure {
    key: string;
    label: string;
    value: number;
    prior: number;
    money: boolean;
}

interface RecentOrder {
    uuid: string;
    productName: string;
    vendorName: string;
    customerName: string;
    status: string;
    statusLabel: string;
    priceKobo: number;
    placedAt: string;
    href: string;
}

interface Props extends PageProps {
    queues: Queue[];
    setup: SetupStep[];
    figures: Figure[];
    recentOrders: RecentOrder[];
}

const TONE: Record<Queue['tone'], { bar: string; text: string }> = {
    brand: { bar: 'bg-brand-500', text: 'text-brand-700' },
    emerald: { bar: 'bg-emerald-500', text: 'text-emerald-700' },
    amber: { bar: 'bg-amber-500', text: 'text-amber-700' },
    red: { bar: 'bg-red-500', text: 'text-red-700' },
    violet: { bar: 'bg-violet-500', text: 'text-violet-700' },
    slate: { bar: 'bg-slate-400', text: 'text-slate-700' },
};

/**
 * The administrator's home screen.
 *
 * Built to answer one question — what needs me today — rather than to report
 * figures nobody acts on. Every number links to the screen that clears it,
 * and a queue at zero is hidden rather than displayed as a proud zero, so
 * the page is only ever as long as the work actually is.
 */
export default function AdminDashboard() {
    const {
        auth,
        queues = [],
        setup = [],
        figures = [],
        recentOrders = [],
    } = usePage<Props>().props;

    const firstName = (auth.user?.name ?? '').split(/\s+/)[0];
    const waiting = queues.filter((queue) => queue.count > 0);
    const urgent = waiting.filter((queue) => queue.urgent);
    const totalWaiting = waiting.reduce((sum, queue) => sum + queue.count, 0);

    return (
        <AdminLayout>
            <Head title="Overview" />

            <Reveal>
                <div className="relative overflow-hidden rounded-3xl bg-gradient-to-r from-brand-700 via-brand-600 to-brand-900 px-6 py-8 text-white sm:px-10">
                    <span
                        className="pointer-events-none absolute -right-5 -top-8 select-none text-[9rem] leading-none opacity-10"
                        aria-hidden="true"
                    >
                        📊
                    </span>
                    <div className="relative z-[1]">
                        <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-yellow">
                            Staff workspace
                        </p>
                        <h1 className="mt-2 text-2xl font-extrabold tracking-tight sm:text-3xl">
                            {greeting()}
                            {firstName ? `, ${firstName}` : ''}
                        </h1>
                        <p className="mt-1 text-sm text-white/80">
                            {totalWaiting === 0
                                ? 'Nothing is waiting on you right now.'
                                : `${totalWaiting} thing${totalWaiting === 1 ? '' : 's'} waiting across ${waiting.length} queue${waiting.length === 1 ? '' : 's'}.`}
                        </p>
                    </div>
                </div>
            </Reveal>

            {/* ── Setup, while anything is unconfigured ──
                Disappears for good once every step is done, so it is a
                one-time scaffold rather than permanent furniture. */}
            {setup.length > 0 && (
                <Reveal delay={50}>
                    <Card className="mt-6 border-brand-200 bg-brand-50/50 p-5">
                        <div className="flex items-start gap-3">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white">
                                <Rocket className="h-4 w-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <h2 className="text-base font-extrabold text-brand-900">
                                    Finish setting up your marketplace
                                </h2>
                                <p className="mt-0.5 text-sm text-brand-800">
                                    {setup.filter((step) => step.done).length} of {setup.length} done.
                                    Until these are set, parts of the shop will look broken rather
                                    than empty.
                                </p>

                                <ol className="mt-4 space-y-2">
                                    {setup.map((step) => (
                                        <li key={step.label}>
                                            {step.done ? (
                                                <span className="flex items-start gap-2.5 rounded-xl px-3 py-2 text-sm">
                                                    <Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                                    <span className="text-gray-400 line-through">
                                                        {step.label}
                                                    </span>
                                                </span>
                                            ) : (
                                                <Link
                                                    href={step.href}
                                                    className="flex items-start gap-2.5 rounded-xl bg-white px-3 py-2.5 shadow-sm transition hover:shadow-md"
                                                >
                                                    <span className="mt-0.5 h-4 w-4 shrink-0 rounded-full border-2 border-brand-300" />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block text-sm font-bold text-gray-900">
                                                            {step.label}
                                                        </span>
                                                        <span className="block text-xs text-gray-500">
                                                            {step.why}
                                                        </span>
                                                    </span>
                                                    <ArrowRight className="mt-0.5 h-4 w-4 shrink-0 text-brand-500" />
                                                </Link>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </div>
                    </Card>
                </Reveal>
            )}

            {/* ── Urgent ──
                Somebody has paid and nothing is moving. Separated from the
                ordinary queue because "a customer is stuck" and "six listings
                to review" are not the same kind of tired. */}
            {urgent.length > 0 && (
                <Reveal delay={100}>
                    <Card className="mt-4 border-red-200 bg-red-50/60 p-4">
                        <h2 className="flex items-center gap-2 text-sm font-extrabold text-red-800">
                            <AlertTriangle className="h-4 w-4" /> Needs a decision
                        </h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {urgent.map((queue) => (
                                <Link
                                    key={queue.key}
                                    href={queue.href}
                                    className="inline-flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm shadow-sm transition hover:shadow-md"
                                >
                                    <span className="font-extrabold tabular-nums text-red-700">
                                        {queue.count}
                                    </span>
                                    <span className="font-semibold text-gray-700">{queue.label}</span>
                                    <ArrowRight className="h-3.5 w-3.5 text-gray-400" />
                                </Link>
                            ))}
                        </div>
                    </Card>
                </Reveal>
            )}

            {/* ── The work ── */}
            <Reveal delay={150}>
                <h2 className="mb-3 mt-8 text-sm font-bold uppercase tracking-wide text-gray-500">
                    Waiting on you
                </h2>
            </Reveal>

            {waiting.length === 0 ? (
                <Card className="px-6 py-12 text-center">
                    <CheckCircle2 className="mx-auto h-10 w-10 text-emerald-500" />
                    <p className="mt-3 font-bold text-gray-800">Every queue is clear.</p>
                    <p className="mt-1 text-sm text-gray-500">
                        New approvals, orders and deliveries will appear here as they arrive.
                    </p>
                </Card>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {waiting.map((queue) => {
                        const tone = TONE[queue.tone];

                        return (
                            <Link
                                key={queue.key}
                                href={queue.href}
                                className="group relative overflow-hidden rounded-2xl border border-gray-100 bg-white p-4 pl-5 shadow-sm transition hover:shadow-md"
                            >
                                <span
                                    className={`absolute inset-y-0 left-0 w-1.5 ${tone.bar}`}
                                    aria-hidden="true"
                                />
                                <p className={`text-3xl font-extrabold tabular-nums ${tone.text}`}>
                                    {queue.count}
                                </p>
                                <p className="mt-0.5 text-sm font-semibold text-gray-700">
                                    {queue.label}
                                </p>
                                <span className="mt-2 inline-flex items-center gap-1 text-xs font-bold text-gray-400 transition group-hover:text-gray-700">
                                    Open <ArrowRight className="h-3 w-3" />
                                </span>
                            </Link>
                        );
                    })}
                </div>
            )}

            {/* ── The numbers ── */}
            <Reveal delay={200}>
                <h2 className="mb-3 mt-8 text-sm font-bold uppercase tracking-wide text-gray-500">
                    Last 30 days
                </h2>
            </Reveal>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {figures.map((figure) => (
                    <Card key={figure.key} className="p-4">
                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                            {figure.label}
                        </p>
                        <p className="mt-1 text-2xl font-extrabold tabular-nums text-gray-900">
                            {figure.money
                                ? formatNairaFromKobo(figure.value)
                                : figure.value.toLocaleString('en-NG')}
                        </p>
                        {/* A bare figure says nothing — ₦2.4m is either very
                            good or very bad depending on last month. */}
                        <Trend value={figure.value} prior={figure.prior} />
                    </Card>
                ))}
            </div>

            {/* ── Recent orders ── */}
            {recentOrders.length > 0 && (
                <>
                    <Reveal delay={250}>
                        <div className="mb-3 mt-8 flex items-center justify-between">
                            <h2 className="text-sm font-bold uppercase tracking-wide text-gray-500">
                                Latest orders
                            </h2>
                            <Link
                                href={route('admin.orders.index')}
                                className="text-xs font-bold text-brand-600 hover:text-brand-700"
                            >
                                All orders →
                            </Link>
                        </div>
                    </Reveal>

                    <Card className="overflow-hidden">
                        <ul className="divide-y divide-gray-50">
                            {recentOrders.map((order) => (
                                <li key={order.uuid}>
                                    <Link
                                        href={order.href}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 transition hover:bg-gray-50/60"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-bold text-gray-900">
                                                {order.productName}
                                            </span>
                                            <span className="block truncate text-xs text-gray-500">
                                                {order.vendorName} → {order.customerName} ·{' '}
                                                {order.placedAt}
                                            </span>
                                        </span>
                                        <span className="rounded-lg bg-gray-100 px-2 py-1 text-[11px] font-bold text-gray-600">
                                            {order.statusLabel}
                                        </span>
                                        <span className="text-sm font-bold tabular-nums text-gray-900">
                                            {formatNairaFromKobo(order.priceKobo)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </>
            )}
        </AdminLayout>
    );
}

function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';

    return 'Good evening';
}

/**
 * Movement against the previous thirty days.
 *
 * Says "nothing yet" rather than showing a meaningless +100% or dividing by
 * zero — a percentage against nothing is not a comparison.
 */
function Trend({ value, prior }: { value: number; prior: number }) {
    if (prior === 0 && value === 0) {
        return <p className="mt-1 text-[11px] text-gray-400">Nothing yet</p>;
    }

    if (prior === 0) {
        return (
            <p className="mt-1 text-[11px] font-semibold text-emerald-600">
                First in this window
            </p>
        );
    }

    const change = Math.round(((value - prior) / prior) * 100);

    if (change === 0) {
        return <p className="mt-1 text-[11px] text-gray-400">→ level with the 30 days before</p>;
    }

    return (
        <p
            className={`mt-1 text-[11px] font-semibold ${
                change > 0 ? 'text-emerald-600' : 'text-red-600'
            }`}
        >
            {change > 0 ? '↑' : '↓'} {Math.abs(change)}% vs the 30 days before
        </p>
    );
}
