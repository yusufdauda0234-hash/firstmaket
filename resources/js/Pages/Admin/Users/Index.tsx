import { Badge, statusTone } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, Search, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface UserRow {
    uuid: string;
    name: string;
    email: string | null;
    phone: string | null;
    status: string;
    joinedAt: string;
}

interface Props {
    users: {
        data: UserRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    query: string;
    status: string;
    [key: string]: unknown;
}

const statusTabs = ['', 'active', 'suspended', 'banned', 'pending_verification'];
const statusLabel: Record<string, string> = {
    '': 'All',
    active: 'Active',
    suspended: 'Suspended',
    banned: 'Banned',
    pending_verification: 'Pending verification',
};

export default function UsersIndex() {
    const { users, query, status } = usePage<Props>().props;
    const [search, setSearch] = useState(query);

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.users.index'), { q: search, status }, { preserveState: true });
    };

    return (
        <AdminLayout>
            <Head title="Customers" />

            <PageHeader
                eyebrow="Operational controls"
                title="Customers"
                description="Search customer accounts and suspend or ban when necessary — sessions end on the account's next request."
            />

            <form onSubmit={submitSearch} className="mb-4 flex items-center gap-2">
                <div className="flex flex-1 items-center rounded-full border border-gray-200 bg-white px-4 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/15">
                    <Search className="h-4 w-4 text-gray-400" />
                    <input
                        type="text"
                        placeholder="Search by name, email, or phone"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-full border-0 bg-transparent px-3 py-2.5 text-sm focus:outline-none focus:ring-0"
                    />
                </div>
                <button
                    type="submit"
                    className="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                >
                    Search
                </button>
            </form>

            <div className="mb-4 flex flex-wrap gap-2">
                {statusTabs.map((tab) => (
                    <Link
                        key={tab || 'all'}
                        href={route('admin.users.index', { status: tab, q: query })}
                        className={
                            tab === status
                                ? 'rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-600/25'
                                : 'rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-600 transition hover:border-brand-300 hover:text-brand-700'
                        }
                    >
                        {statusLabel[tab]}
                    </Link>
                ))}
            </div>

            <Reveal>
                <Card className="overflow-hidden p-0">
                    {users.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                                <Users className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">No customers found</p>
                            <p className="mt-1 text-sm text-gray-500">Try a different search or status filter.</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-gray-100">
                            {users.data.map((user) => (
                                <Link
                                    key={user.uuid}
                                    href={route('admin.users.show', user.uuid)}
                                    className="group flex w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-brand-50/50"
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold text-gray-900 group-hover:text-brand-700">
                                            {user.name}
                                        </span>
                                        <span className="block truncate text-sm text-gray-500">
                                            {user.email ?? user.phone ?? '—'}
                                        </span>
                                    </span>
                                    <span className="hidden shrink-0 text-xs text-gray-400 sm:block">{user.joinedAt}</span>
                                    <Badge tone={statusTone(user.status)}>{user.status.replace('_', ' ')}</Badge>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-gray-300 transition-transform group-hover:translate-x-1 group-hover:text-brand-500" />
                                </Link>
                            ))}
                        </div>
                    )}
                </Card>
            </Reveal>

            {users.links.length > 3 && (
                <div className="mt-4 flex flex-wrap gap-1">
                    {users.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                                className={
                                    link.active
                                        ? 'rounded-full bg-brand-600 px-3 py-1.5 text-sm text-white'
                                        : 'rounded-full border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-600 transition hover:border-brand-300 hover:text-brand-700'
                                }
                            />
                        ) : (
                            <span
                                key={index}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                                className="rounded-full border border-gray-200 px-3 py-1.5 text-sm text-gray-400"
                            />
                        ),
                    )}
                </div>
            )}
        </AdminLayout>
    );
}
