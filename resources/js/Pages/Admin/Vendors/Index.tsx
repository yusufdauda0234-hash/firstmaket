import VendorReviewModal from '@/Components/domain/admin/VendorReviewModal';
import { Badge, statusTone } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Store } from 'lucide-react';
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
    vendors: {
        data: VendorRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
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

    const hasPermission = (permission: string) =>
        auth.user !== null &&
        (auth.user.permissions.includes(permission) || auth.user.roles.includes('Super Administrator'));

    return (
        <AdminLayout>
            <Head title="Vendor approvals" />

            <VendorReviewModal
                uuid={reviewUuid}
                canApprove={hasPermission('vendors.approve')}
                canSuspend={hasPermission('vendors.suspend')}
                onClose={() => setReviewUuid(null)}
            />

            <PageHeader
                eyebrow="Marketplace operations"
                title="Vendors"
                description="Review seller applications and keep the marketplace trusted."
                actions={
                    <span className="rounded-full bg-brand-yellow px-3 py-1.5 text-xs font-bold text-brand-900">
                        Approval queue
                    </span>
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
                    ) : (
                        <div className="divide-y divide-gray-100">
                            {vendors.data.map((vendor, index) => (
                                <button
                                    key={vendor.uuid}
                                    type="button"
                                    onClick={() => setReviewUuid(vendor.uuid)}
                                    className="group flex w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-brand-50/50"
                                >
                                    <span
                                        className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-sm font-extrabold text-white shadow-sm ${gradients[index % gradients.length]}`}
                                    >
                                        {initials(vendor.businessName)}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold text-gray-900 group-hover:text-brand-700">
                                            {vendor.businessName}
                                        </span>
                                        <span className="block truncate text-sm text-gray-500">
                                            {vendor.contactName} · {vendor.email}
                                        </span>
                                    </span>
                                    <span className="hidden shrink-0 text-xs text-gray-400 sm:block">
                                        {vendor.registeredAt}
                                    </span>
                                    <Badge tone={statusTone(vendor.status)}>{vendor.status}</Badge>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-gray-300 transition-transform group-hover:translate-x-1 group-hover:text-brand-500" />
                                </button>
                            ))}
                        </div>
                    )}
                </Card>
            </Reveal>

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
