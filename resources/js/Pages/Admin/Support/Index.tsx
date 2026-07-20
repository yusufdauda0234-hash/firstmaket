import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination } from '@/Components/ui/Pagination';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/Utils/cn';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, Headphones, Search } from 'lucide-react';

interface TicketRow {
    uuid: string;
    subject: string;
    customerName: string;
    assigneeName: string | null;
    channel: string;
    status: string;
    priority: string;
    createdAt: string;
}

interface HotlineRow {
    id: number;
    customerName: string;
    phone: string;
    reason: string;
    ticketUuid: string | null;
    requestedAt: string;
}

interface Props {
    tickets: {
        data: TicketRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    hotlineQueue: HotlineRow[];
    filters: { status: string | null };
    openCount: number;
    [key: string]: unknown;
}

const TABS = [
    { value: '', label: 'All' },
    { value: 'open', label: 'Open' },
    { value: 'pending', label: 'Awaiting customer' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
];

const statusStyle: Record<string, string> = {
    open: 'bg-sky-50 text-sky-700',
    pending: 'bg-amber-50 text-amber-700',
    resolved: 'bg-emerald-50 text-emerald-700',
    closed: 'bg-gray-100 text-gray-500',
};

export default function SupportAdminIndex() {
    const { tickets, hotlineQueue, filters, openCount } = usePage<Props>().props;

    const apply = (status: string) =>
        router.get(route('admin.support.index'), status ? { status } : {}, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <AdminLayout>
            <Head title="Support" />

            <PageHeader
                eyebrow="Customer care"
                title="Support"
                description="Ticket queue, hotline callbacks, and safe customer lookup."
                actions={
                    <div className="flex items-center gap-2">
                        {openCount > 0 && (
                            <span className="rounded-full bg-brand-yellow px-3 py-1.5 text-xs font-bold text-brand-900">
                                {openCount} open
                            </span>
                        )}
                        <Link
                            href={route('admin.support.lookup')}
                            className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-brand-300 hover:text-brand-700"
                        >
                            <Search className="h-4 w-4" /> Customer lookup
                        </Link>
                    </div>
                }
            />

            {/* ── Hotline queue ── */}
            {hotlineQueue.length > 0 && (
                <Card className="mb-4 p-0">
                    <h2 className="flex items-center gap-2 border-b border-gray-100 px-5 py-4 text-sm font-bold text-gray-900">
                        <Headphones className="h-4 w-4 text-brand-600" /> Hotline callback queue
                    </h2>
                    <ul className="divide-y divide-gray-100">
                        {hotlineQueue.map((call) => (
                            <li key={call.id} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-gray-900">
                                        {call.customerName} · {call.phone}
                                    </p>
                                    <p className="text-xs text-gray-400">
                                        {call.reason} · requested {call.requestedAt}
                                    </p>
                                </div>
                                {call.ticketUuid && (
                                    <Link
                                        href={route('admin.support.show', call.ticketUuid)}
                                        className="text-sm font-semibold text-brand-600 hover:underline"
                                    >
                                        Open ticket →
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {/* ── Ticket queue ── */}
            <div className="mb-4 flex flex-wrap gap-2">
                {TABS.map((tab) => (
                    <button
                        key={tab.value}
                        type="button"
                        onClick={() => apply(tab.value)}
                        className={
                            (filters.status ?? '') === tab.value
                                ? 'rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm'
                                : 'rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-600 transition hover:border-brand-300 hover:text-brand-700'
                        }
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            <Card className="overflow-hidden p-0">
                {tickets.data.length === 0 ? (
                    <p className="px-6 py-14 text-center text-sm text-gray-500">No tickets match this filter.</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {tickets.data.map((ticket) => (
                            <li key={ticket.uuid}>
                                <Link
                                    href={route('admin.support.show', ticket.uuid)}
                                    className="flex items-center gap-4 px-5 py-4 transition hover:bg-slate-50"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="flex items-center gap-2 truncate text-sm font-bold text-gray-900">
                                            {ticket.subject}
                                            {ticket.priority === 'high' && (
                                                <span className="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold uppercase text-red-700">
                                                    High
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-400">
                                            {ticket.customerName} · {ticket.channel} · {ticket.createdAt}
                                            {ticket.assigneeName && ` · assigned to ${ticket.assigneeName}`}
                                        </p>
                                    </div>
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase',
                                            statusStyle[ticket.status] ?? 'bg-gray-100 text-gray-500',
                                        )}
                                    >
                                        {ticket.status}
                                    </span>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-gray-300" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Pagination links={tickets.links} />
        </AdminLayout>
    );
}
