import AddCustomerModal from '@/Components/domain/admin/AddCustomerModal';
import { Badge, statusTone } from '@/Components/ui/Badge';
import BulkActionBar from '@/Components/ui/BulkActionBar';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import ViewToggle from '@/Components/ui/ViewToggle';
import { useRowSelection } from '@/Hooks/useRowSelection';
import { useViewMode } from '@/Hooks/useViewMode';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps, Paginated } from '@/Types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronRight, Plus, Search, Users } from 'lucide-react';
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
    users: Paginated<UserRow>;
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
    const { users, query, status, auth } = usePage<Props & PageProps>().props;
    const [search, setSearch] = useState(query);
    const [adding, setAdding] = useState(false);
    // Account admin is column-scanning work, so table leads here.
    const { mode, choose } = useViewMode('admin.customers', 'table');

    const canModerate =
        auth.user !== null &&
        (auth.user.permissions.includes('customers.suspend') ||
            auth.user.roles.includes('Super Administrator'));

    const selection = useRowSelection(users.data.map((u) => u.uuid));
    const bulk = useForm<{ action: string; uuids: string[]; reason: string }>({
        action: 'suspend',
        uuids: [],
        reason: '',
    });

    const firstIndex = (users.from ?? 1) - 1;

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.users.index'), { q: search, status }, { preserveState: true });
    };

    function runBulk(action: 'suspend' | 'reactivate', reason = '') {
        bulk.transform(() => ({ action, uuids: selection.ids, reason }));
        bulk.post(route('admin.users.bulk'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    return (
        <AdminLayout>
            <Head title="Customers" />

            {canModerate && <AddCustomerModal open={adding} onClose={() => setAdding(false)} />}

            <PageHeader
                eyebrow="Operational controls"
                title="Customers"
                description="Search customer accounts and suspend or ban when necessary — sessions end on the account's next request."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <ViewToggle mode={mode} onChange={choose} label="customers" />
                        {canModerate && (
                            <button
                                type="button"
                                onClick={() => setAdding(true)}
                                className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700"
                            >
                                <Plus className="h-4 w-4" /> Add customer
                            </button>
                        )}
                    </div>
                }
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
                    ) : mode === 'table' ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] text-sm">
                                <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th className="w-10 py-3 pl-5 pr-2">
                                            <RowCheckbox
                                                checked={selection.allSelected}
                                                indeterminate={selection.someSelected}
                                                onChange={selection.toggleAll}
                                                label="Select all customers on this page"
                                            />
                                        </th>
                                        <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                        <th className="px-5 py-3 font-semibold">Customer</th>
                                        <th className="px-5 py-3 font-semibold">Contact</th>
                                        <th className="px-5 py-3 font-semibold">Joined</th>
                                        <th className="px-5 py-3 font-semibold">Status</th>
                                        <th className="w-10 px-5 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {users.data.map((user, index) => (
                                        <tr
                                            key={user.uuid}
                                            onClick={() => router.visit(route('admin.users.show', user.uuid))}
                                            className={`group cursor-pointer transition-colors hover:bg-brand-50/50 ${
                                                selection.isSelected(user.uuid) ? 'bg-brand-50/70' : ''
                                            }`}
                                        >
                                            <td className="py-3.5 pl-5 pr-2">
                                                <RowCheckbox
                                                    checked={selection.isSelected(user.uuid)}
                                                    onChange={() => selection.toggle(user.uuid)}
                                                    label={`Select ${user.name}`}
                                                />
                                            </td>
                                            <td className="px-2 py-3.5 text-xs tabular-nums text-gray-400">
                                                {firstIndex + index + 1}
                                            </td>
                                            <td className="px-5 py-3.5 font-semibold text-gray-900 group-hover:text-brand-700">
                                                {user.name}
                                            </td>
                                            <td className="px-5 py-3.5 text-gray-600">
                                                {user.email ?? '—'}
                                                {user.phone && (
                                                    <span className="block text-xs text-gray-400">
                                                        {user.phone}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-5 py-3.5 text-xs text-gray-500">{user.joinedAt}</td>
                                            <td className="px-5 py-3.5">
                                                <Badge tone={statusTone(user.status)}>
                                                    {user.status.replace('_', ' ')}
                                                </Badge>
                                            </td>
                                            <td className="px-5 py-3.5">
                                                <ChevronRight className="h-4 w-4 text-gray-300 transition-transform group-hover:translate-x-1 group-hover:text-brand-500" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
                            {users.data.map((user) => (
                                <div
                                    key={user.uuid}
                                    className={`flex flex-col rounded-xl border p-4 transition ${
                                        selection.isSelected(user.uuid)
                                            ? 'border-brand-300 bg-brand-50/60'
                                            : 'border-gray-100 hover:border-brand-200 hover:shadow-md hover:shadow-brand-600/5'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <RowCheckbox
                                            checked={selection.isSelected(user.uuid)}
                                            onChange={() => selection.toggle(user.uuid)}
                                            label={`Select ${user.name}`}
                                        />
                                        <Badge tone={statusTone(user.status)}>
                                            {user.status.replace('_', ' ')}
                                        </Badge>
                                    </div>
                                    <Link
                                        href={route('admin.users.show', user.uuid)}
                                        className="group mt-2 block"
                                    >
                                        <span className="block truncate font-bold text-gray-900 group-hover:text-brand-700">
                                            {user.name}
                                        </span>
                                        <span className="block truncate text-sm text-gray-500">
                                            {user.email ?? user.phone ?? '—'}
                                        </span>
                                    </Link>
                                    <span className="mt-3 border-t border-gray-100 pt-2.5 text-xs text-gray-400">
                                        Joined {user.joinedAt}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </Reveal>

            <BulkActionBar
                count={selection.count}
                noun="customer"
                processing={bulk.processing}
                onClear={selection.clear}
                actions={
                    canModerate
                        ? [
                              { label: 'Reactivate', tone: 'primary', run: () => runBulk('reactivate') },
                              {
                                  label: 'Suspend',
                                  tone: 'danger',
                                  needsReason: true,
                                  reasonPlaceholder: 'e.g. Repeated chargebacks under review',
                                  run: (reason) => runBulk('suspend', reason),
                              },
                          ]
                        : []
                }
            />

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
