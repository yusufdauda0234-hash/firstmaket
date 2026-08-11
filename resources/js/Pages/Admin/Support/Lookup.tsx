import { Badge, statusTone } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search, User as UserIcon } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ResultRow {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    joined: string;
}

interface Props {
    query: string;
    results: ResultRow[];
    customer: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
        emailVerified: boolean;
        phoneVerified: boolean;
        memberSince: string;
        /** False for staff without savings.view — the financial cards are hidden entirely. */
        canSeeFinancials: boolean;
        savingsBalanceKobo: number | null;
        orders: { uuid: string; productName: string; status: string; statusLabel: string; lockedPriceKobo: number; createdAt: string }[];
        savingsGoals: { uuid: string; productNames: string; status: string; targetKobo: number }[];
        tickets: { uuid: string; subject: string; status: string }[];
    } | null;
    [key: string]: unknown;
}

/**
 * Read-only customer lookup for support agents. Shows order/goal/savings
 * context only — card details are never stored.
 */
export default function CustomerLookup() {
    const { query, results, customer } = usePage<Props>().props;
    const [term, setTerm] = useState(query);

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.support.lookup'), { q: term }, { preserveState: true });
    };

    return (
        <AdminLayout>
            <Head title="Customer lookup" />

            <PageHeader
                eyebrow="Support tools"
                title="Customer lookup"
                description="Read-only order, goal, and savings context. No card data exists on FirstMaket and identity numbers are never shown."
            />

            <form onSubmit={search} className="mb-4 flex max-w-xl items-center gap-2">
                <div className="flex flex-1 items-center rounded-full border border-gray-200 bg-white px-4 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/15">
                    <Search className="h-4 w-4 text-gray-400" />
                    <input
                        type="text"
                        placeholder="Search by name, email, or phone…"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        className="w-full border-0 bg-transparent px-2 py-2.5 text-sm focus:outline-none focus:ring-0"
                    />
                </div>
                <button
                    type="submit"
                    className="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                >
                    Search
                </button>
            </form>

            {/* ── Results ── */}
            {results.length > 0 && (
                <Card className="mb-4 max-w-xl p-0">
                    <ul className="divide-y divide-gray-100">
                        {results.map((result) => (
                            <li key={result.id}>
                                <Link
                                    href={route('admin.support.lookup', { q: query, customer: result.id })}
                                    preserveState
                                    className="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50"
                                >
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                                        <UserIcon className="h-4 w-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-semibold text-gray-900">
                                            {result.name}
                                        </span>
                                        <span className="block text-xs text-gray-400">
                                            {result.email ?? result.phone} · joined {result.joined}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {query !== '' && results.length === 0 && !customer && (
                <p className="text-sm text-gray-500">No customers match “{query}”.</p>
            )}

            {/* ── Customer context ── */}
            {customer && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Profile</h2>
                        <p className="mt-2 text-base font-bold text-gray-900">{customer.name}</p>
                        <div className="mt-2 space-y-1.5 text-sm text-gray-600">
                            {customer.email && (
                                <p className="flex items-center gap-2">
                                    {customer.email}
                                    <Badge tone={customer.emailVerified ? 'success' : 'warning'}>
                                        {customer.emailVerified ? 'verified' : 'unverified'}
                                    </Badge>
                                </p>
                            )}
                            {customer.phone && (
                                <p className="flex items-center gap-2">
                                    {customer.phone}
                                    <Badge tone={customer.phoneVerified ? 'success' : 'warning'}>
                                        {customer.phoneVerified ? 'verified' : 'unverified'}
                                    </Badge>
                                </p>
                            )}
                            <p className="text-xs text-gray-400">Member since {customer.memberSince}</p>
                        </div>
                    </Card>

                    {customer.canSeeFinancials && (
                        <Card>
                            <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Savings</h2>
                            <p className="mt-2 text-2xl font-extrabold tracking-tight text-gray-900">
                                {formatNairaFromKobo(customer.savingsBalanceKobo ?? 0)}
                            </p>
                            <p className="mt-1 text-xs text-gray-400">
                                Deposit-only balance · card details are held by Paystack, never FirstMaket.
                            </p>
                        </Card>
                    )}

                    <Card className="p-0">
                        <h2 className="border-b border-gray-100 px-5 py-3.5 text-xs font-bold uppercase tracking-wide text-gray-500">
                            Recent orders
                        </h2>
                        {customer.orders.length === 0 ? (
                            <p className="px-5 py-8 text-center text-sm text-gray-400">No orders.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {customer.orders.map((order) => (
                                    <li key={order.uuid} className="flex items-center gap-3 px-5 py-3">
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-semibold text-gray-900">
                                                {order.productName}
                                            </span>
                                            <span className="block text-xs text-gray-400">
                                                {order.createdAt} · {formatNairaFromKobo(order.lockedPriceKobo)}
                                            </span>
                                        </span>
                                        <Badge tone={statusTone(order.status)}>{order.statusLabel}</Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card className={customer.canSeeFinancials ? 'p-0' : 'hidden'}>
                        <h2 className="border-b border-gray-100 px-5 py-3.5 text-xs font-bold uppercase tracking-wide text-gray-500">
                            Savings goals
                        </h2>
                        {customer.savingsGoals.length === 0 ? (
                            <p className="px-5 py-8 text-center text-sm text-gray-400">No savings goals.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {customer.savingsGoals.map((goal) => (
                                    <li key={goal.uuid} className="flex items-center gap-3 px-5 py-3">
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-semibold text-gray-900">
                                                {goal.productNames}
                                            </span>
                                            <span className="block text-xs text-gray-400">
                                                Target {formatNairaFromKobo(goal.targetKobo)}
                                            </span>
                                        </span>
                                        <Badge tone={statusTone(goal.status)}>{goal.status.replace(/_/g, ' ')}</Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {customer.tickets.length > 0 && (
                        <Card className="p-0 lg:col-span-2">
                            <h2 className="border-b border-gray-100 px-5 py-3.5 text-xs font-bold uppercase tracking-wide text-gray-500">
                                Support history
                            </h2>
                            <ul className="divide-y divide-gray-100">
                                {customer.tickets.map((ticket) => (
                                    <li key={ticket.uuid}>
                                        <Link
                                            href={route('admin.support.show', ticket.uuid)}
                                            className="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50"
                                        >
                                            <span className="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900">
                                                {ticket.subject}
                                            </span>
                                            <Badge tone={statusTone(ticket.status)}>{ticket.status}</Badge>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            )}
        </AdminLayout>
    );
}
