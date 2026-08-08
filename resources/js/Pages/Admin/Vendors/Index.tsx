import AddVendorModal from '@/Components/domain/admin/AddVendorModal';
import VendorReviewModal from '@/Components/domain/admin/VendorReviewModal';
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
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronRight, Plus, Store } from 'lucide-react';
import { useState } from 'react';

interface VendorRow {
    uuid: string;
    businessName: string;
    contactName: string;
    email: string;
    status: string;
    registeredAt: string;
}

interface Props {
    vendors: Paginated<VendorRow>;
    status: string;
    [key: string]: unknown;
}

const statusTabs = ['pending', 'approved', 'rejected', 'suspended', 'banned'];

const gradients = [
    'from-brand-500 to-brand-700',
    'from-emerald-500 to-emerald-700',
    'from-violet-500 to-violet-700',
    'from-amber-500 to-orange-600',
    'from-rose-500 to-pink-600',
];

function initials(name: string) {
    return name
        .split(/\s+/)
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

export default function VendorsIndex() {
    const { vendors, status, auth } = usePage<Props & PageProps>().props;
    const [reviewUuid, setReviewUuid] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    // Vendor admin is mostly column-scanning, so table is the sensible default.
    const { mode, choose } = useViewMode('admin.vendors', 'table');

    const hasPermission = (permission: string) =>
        auth.user !== null &&
        (auth.user.permissions.includes(permission) || auth.user.roles.includes('Super Administrator'));

    const canCreate = hasPermission('vendors.approve');

    const selection = useRowSelection(vendors.data.map((v) => v.uuid));
    const bulk = useForm<{ action: string; uuids: string[]; reason: string }>({
        action: 'approve',
        uuids: [],
        reason: '',
    });

    // Approve/reject only mean something for applications still pending.
    const canDecide = status === 'pending' && hasPermission('vendors.approve');
    const firstIndex = (vendors.from ?? 1) - 1;

    function runBulk(action: 'approve' | 'reject', reason = '') {
        bulk.transform(() => ({ action, uuids: selection.ids, reason }));
        bulk.post(route('admin.vendors.bulk'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    return (
        <AdminLayout>
            <Head title="Vendors" />

            <VendorReviewModal
                uuid={reviewUuid}
                canApprove={hasPermission('vendors.approve')}
                canSuspend={hasPermission('vendors.suspend')}
                onClose={() => setReviewUuid(null)}
            />

            {canCreate && <AddVendorModal open={adding} onClose={() => setAdding(false)} />}

            <PageHeader
                eyebrow="Marketplace operations"
                title="Vendors"
                description="Review seller applications and keep the marketplace trusted."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <ViewToggle mode={mode} onChange={choose} label="vendors" />
                        {canCreate && (
                            <button
                                type="button"
                                onClick={() => setAdding(true)}
                                className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700"
                            >
                                <Plus className="h-4 w-4" /> Add vendor
                            </button>
                        )}
                    </div>
                }
            />

            <div className="mb-4 flex flex-wrap gap-2">
                {statusTabs.map((tab) => (
                    <Link
                        key={tab}
                        href={route('admin.vendors.index', { status: tab })}
                        className={
                            tab === status
                                ? 'rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold capitalize text-white shadow-sm shadow-brand-600/25'
                                : 'rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium capitalize text-gray-600 transition hover:border-brand-300 hover:text-brand-700'
                        }
                    >
                        {tab}
                    </Link>
                ))}
            </div>

            <Reveal>
                <Card className="overflow-hidden p-0">
                    {vendors.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                                <Store className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">No {status} vendors</p>
                            <p className="mt-1 text-sm text-gray-500">Applications with this status will show up here.</p>
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
                                                label="Select all vendors on this page"
                                            />
                                        </th>
                                        <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                        <th className="px-5 py-3 font-semibold">Business</th>
                                        <th className="px-5 py-3 font-semibold">Contact</th>
                                        <th className="px-5 py-3 font-semibold">Registered</th>
                                        <th className="px-5 py-3 font-semibold">Status</th>
                                        <th className="w-10 px-5 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {vendors.data.map((vendor, index) => (
                                        <tr
                                            key={vendor.uuid}
                                            onClick={() => setReviewUuid(vendor.uuid)}
                                            className={`group cursor-pointer transition-colors hover:bg-brand-50/50 ${
                                                selection.isSelected(vendor.uuid) ? 'bg-brand-50/70' : ''
                                            }`}
                                        >
                                            <td className="py-3.5 pl-5 pr-2">
                                                <RowCheckbox
                                                    checked={selection.isSelected(vendor.uuid)}
                                                    onChange={() => selection.toggle(vendor.uuid)}
                                                    label={`Select ${vendor.businessName}`}
                                                />
                                            </td>
                                            <td className="px-2 py-3.5 text-xs tabular-nums text-gray-400">
                                                {firstIndex + index + 1}
                                            </td>
                                            <td className="px-5 py-3.5">
                                                <div className="flex items-center gap-3">
                                                    <span
                                                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br text-xs font-extrabold text-white shadow-sm ${gradients[index % gradients.length]}`}
                                                    >
                                                        {initials(vendor.businessName)}
                                                    </span>
                                                    <span className="font-semibold text-gray-900 group-hover:text-brand-700">
                                                        {vendor.businessName}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-5 py-3.5 text-gray-600">
                                                {vendor.contactName}
                                                <span className="block text-xs text-gray-400">{vendor.email}</span>
                                            </td>
                                            <td className="px-5 py-3.5 text-xs text-gray-500">
                                                {vendor.registeredAt}
                                            </td>
                                            <td className="px-5 py-3.5">
                                                <Badge tone={statusTone(vendor.status)}>{vendor.status}</Badge>
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
                            {vendors.data.map((vendor, index) => (
                                <button
                                    key={vendor.uuid}
                                    type="button"
                                    onClick={() => setReviewUuid(vendor.uuid)}
                                    className="group flex flex-col rounded-xl border border-gray-100 p-4 text-left transition hover:border-brand-200 hover:shadow-md hover:shadow-brand-600/5"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <span
                                            className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-sm font-extrabold text-white shadow-sm ${gradients[index % gradients.length]}`}
                                        >
                                            {initials(vendor.businessName)}
                                        </span>
                                        <Badge tone={statusTone(vendor.status)}>{vendor.status}</Badge>
                                    </div>
                                    <span className="mt-3 line-clamp-2 font-bold text-gray-900 group-hover:text-brand-700">
                                        {vendor.businessName}
                                    </span>
                                    <span className="mt-1 truncate text-sm text-gray-500">{vendor.contactName}</span>
                                    <span className="truncate text-xs text-gray-400">{vendor.email}</span>
                                    <span className="mt-3 border-t border-gray-100 pt-2.5 text-xs text-gray-400">
                                        {vendor.registeredAt}
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                </Card>
            </Reveal>

            <BulkActionBar
                count={selection.count}
                noun="vendor"
                processing={bulk.processing}
                onClear={selection.clear}
                actions={
                    canDecide
                        ? [
                              { label: 'Approve', tone: 'primary', run: () => runBulk('approve') },
                              {
                                  label: 'Reject',
                                  tone: 'danger',
                                  needsReason: true,
                                  reasonPlaceholder: 'e.g. CAC document could not be verified',
                                  run: (reason) => runBulk('reject', reason),
                              },
                          ]
                        : []
                }
            />

            {vendors.links.length > 3 && (
                <div className="mt-4 flex flex-wrap gap-1">
                    {vendors.links.map((link, index) =>
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
