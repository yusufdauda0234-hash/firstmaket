import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Download, FileText, KeyRound, Mail, MapPin, Phone, RotateCcw, ShieldOff } from 'lucide-react';
import { useState } from 'react';

interface DocumentRow {
    uuid: string;
    type: string;
    originalName: string;
    uploadedAt: string;
}

interface Props extends PageProps {
    vendor: {
        uuid: string;
        businessName: string;
        contactName: string;
        address: string | null;
        email: string;
        phone: string;
        status: string;
        rejectionReason: string | null;
        approvedBy: string | null;
        approvedAt: string | null;
        registeredAt: string;
        documents: DocumentRow[];
        approvedProductCount: number;
    };
}

type Action = 'approve' | 'reject' | 'suspend' | 'reinstate';

const actionConfig: Record<
    Action,
    { title: string; blurb: string; confirm: string; icon: typeof CheckCircle2; accent: string; needsReason: boolean; danger: boolean }
> = {
    approve: {
        title: 'Approve this vendor?',
        blurb: 'The vendor gains access to the Vendor Center and can list products for review.',
        confirm: 'Approve vendor',
        icon: CheckCircle2,
        accent: 'bg-emerald-50 text-emerald-600',
        needsReason: false,
        danger: false,
    },
    reject: {
        title: 'Reject this application?',
        blurb: 'Add a clear reason — the vendor sees it and it is recorded in the audit log.',
        confirm: 'Reject application',
        icon: AlertTriangle,
        accent: 'bg-red-50 text-red-600',
        needsReason: true,
        danger: true,
    },
    suspend: {
        title: 'Suspend this vendor?',
        blurb: 'Suspending immediately delists every approved product from the catalog.',
        confirm: 'Suspend vendor',
        icon: ShieldOff,
        accent: 'bg-red-50 text-red-600',
        needsReason: true,
        danger: true,
    },
    reinstate: {
        title: 'Reinstate this vendor?',
        blurb: 'Restores listing access. Delisted products stay off the catalog until resubmitted.',
        confirm: 'Reinstate vendor',
        icon: RotateCcw,
        accent: 'bg-brand-50 text-brand-600',
        needsReason: false,
        danger: false,
    },
};

const docIcon: Record<string, string> = { cac: '🏢', identity: '🪪', address_proof: '📍', other: '📄' };

export default function VendorShow() {
    const { vendor, auth } = usePage<Props>().props;
    const [action, setAction] = useState<Action | null>(null);
    const approveForm = useForm({});
    const rejectForm = useForm({ reason: '' });
    const suspendForm = useForm({ reason: '' });
    const reinstateForm = useForm({});

    const hasPermission = (permission: string) =>
        auth.user !== null &&
        (auth.user.permissions.includes(permission) || auth.user.roles.includes('Super Administrator'));

    const canApprove = hasPermission('vendors.approve');
    const canSuspend = hasPermission('vendors.suspend');
    const [sendingReset, setSendingReset] = useState(false);

    const busy =
        approveForm.processing || rejectForm.processing || suspendForm.processing || reinstateForm.processing;
    const config = action ? actionConfig[action] : null;
    const reasonForm = action === 'reject' ? rejectForm : suspendForm;
    const suspendBlurb =
        vendor.approvedProductCount > 0
            ? `Suspending immediately delists ${vendor.approvedProductCount} approved product${vendor.approvedProductCount === 1 ? '' : 's'} from the catalog — the vendor must resubmit each one after reinstatement.`
            : config?.blurb;
    const modalDescription = action === 'suspend' ? suspendBlurb : config?.blurb;

    const confirmAction = () => {
        if (!action) return;
        const opts = { preserveScroll: true, onSuccess: () => setAction(null) };
        if (action === 'approve') approveForm.post(route('admin.vendors.approve', vendor.uuid), opts);
        if (action === 'reject') rejectForm.post(route('admin.vendors.reject', vendor.uuid), opts);
        if (action === 'suspend') suspendForm.post(route('admin.vendors.suspend', vendor.uuid), opts);
        if (action === 'reinstate') reinstateForm.post(route('admin.vendors.reinstate', vendor.uuid), opts);
    };

    return (
        <AdminLayout>
            <Head title={vendor.businessName} />

            <PageHeader
                eyebrow="Vendor review"
                title={vendor.businessName}
                backHref={route('admin.vendors.index')}
                backLabel="Back to vendors"
                actions={<Badge tone={statusTone(vendor.status)} className="text-sm">{vendor.status}</Badge>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {/* Business details */}
                <Card className="rounded-2xl shadow-sm lg:col-span-2">
                    <h2 className="mb-4 text-lg font-extrabold text-gray-900">Business details</h2>
                    <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        <Detail icon={<span className="text-gray-400">👤</span>} label="Contact person" value={vendor.contactName} />
                        <Detail icon={<Mail className="h-4 w-4 text-gray-400" />} label="Email" value={vendor.email} />
                        <Detail icon={<Phone className="h-4 w-4 text-gray-400" />} label="Phone" value={vendor.phone} />
                        <Detail icon={<MapPin className="h-4 w-4 text-gray-400" />} label="Address" value={vendor.address ?? '—'} />
                        <Detail icon={<span className="text-gray-400">🗓️</span>} label="Registered" value={vendor.registeredAt} />
                        {vendor.approvedBy && (
                            <Detail
                                icon={<CheckCircle2 className="h-4 w-4 text-emerald-500" />}
                                label="Approved by"
                                value={`${vendor.approvedBy} · ${vendor.approvedAt}`}
                            />
                        )}
                    </dl>

                    {vendor.rejectionReason && (
                        <div className="mt-5 rounded-xl border border-red-100 bg-red-50 px-4 py-3">
                            <p className="text-xs font-bold uppercase tracking-wide text-red-500">Reason on file</p>
                            <p className="mt-1 text-sm text-red-700">{vendor.rejectionReason}</p>
                        </div>
                    )}


                    {/* Documents */}
                    <h3 className="mb-3 mt-6 text-sm font-bold uppercase tracking-wide text-gray-400">Documents</h3>
                    {vendor.documents.length === 0 ? (
                        <p className="rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-400">
                            No documents uploaded.
                        </p>
                    ) : (
                        <ul className="space-y-2">
                            {vendor.documents.map((document) => (
                                <li
                                    key={document.uuid}
                                    className="group flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3 transition hover:border-brand-200 hover:bg-brand-50/40"
                                >
                                    <span className="flex min-w-0 items-center gap-3">
                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-base">
                                            {docIcon[document.type] ?? '📄'}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-semibold text-gray-900">
                                                {document.type.replace('_', ' ')}
                                            </span>
                                            <span className="block truncate text-xs text-gray-400">
                                                {document.originalName} · {document.uploadedAt}
                                            </span>
                                        </span>
                                    </span>
                                    <a
                                        href={route('admin.documents.download', document.uuid)}
                                        className="flex shrink-0 items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-brand-600 transition hover:border-brand-300 hover:bg-brand-50 active:scale-95"
                                    >
                                        <Download className="h-3.5 w-3.5" /> Download
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                {/* Review actions */}
                <div className="space-y-4">
                    {/* Account recovery. Available on any live account — the
                        seller who needs it is usually one who cannot get in. */}
                    {canApprove && vendor.status !== 'rejected' && (
                        <Card className="rounded-2xl shadow-sm">
                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-gray-100 text-gray-600">
                                <KeyRound className="h-5 w-5" />
                            </div>
                            <h2 className="mt-4 text-lg font-extrabold text-gray-900">Password help</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Emails {vendor.email} a one-time link that opens the Vendor Center, where they choose a
                                new password themselves. We never see it, and never set one for them.
                            </p>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={sendingReset}
                                onClick={() => {
                                    setSendingReset(true);
                                    router.post(
                                        route('admin.vendors.password-reset', vendor.uuid),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setSendingReset(false),
                                        },
                                    );
                                }}
                                className="mt-4 w-full active:scale-95"
                            >
                                <KeyRound className="mr-2 h-4 w-4" />
                                {sendingReset ? 'Sending…' : 'Email password link'}
                            </Button>
                        </Card>
                    )}

                    {vendor.status === 'pending' && canApprove && (
                        <Card className="rounded-2xl border-brand-100 bg-gradient-to-br from-brand-50/60 to-white shadow-sm">
                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-600 text-white">
                                <FileText className="h-5 w-5" />
                            </div>
                            <h2 className="mt-4 text-lg font-extrabold text-gray-900">Review application</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Approve a clean application, or reject it with a reason.
                            </p>
                            <Button onClick={() => setAction('approve')} className="mt-4 w-full active:scale-95">
                                <CheckCircle2 className="mr-2 h-4 w-4" /> Approve vendor
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setAction('reject')}
                                className="mt-2 w-full border-red-200 text-red-600 hover:bg-red-50 active:scale-95"
                            >
                                Reject application
                            </Button>
                        </Card>
                    )}

                    {vendor.status === 'approved' && canSuspend && (
                        <Card className="rounded-2xl shadow-sm">
                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-red-50 text-red-600">
                                <ShieldOff className="h-5 w-5" />
                            </div>
                            <h2 className="mt-4 text-lg font-extrabold text-gray-900">Suspend vendor</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Immediately delists every approved product. Listings must be resubmitted after
                                reinstatement.
                            </p>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setAction('suspend')}
                                className="mt-4 w-full border-red-200 text-red-600 hover:bg-red-50 active:scale-95"
                            >
                                Suspend vendor
                            </Button>
                        </Card>
                    )}

                    {vendor.status === 'suspended' && canSuspend && (
                        <Card className="rounded-2xl shadow-sm">
                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                                <RotateCcw className="h-5 w-5" />
                            </div>
                            <h2 className="mt-4 text-lg font-extrabold text-gray-900">Reinstate vendor</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Restores listing access. Delisted products stay off the catalog until resubmitted.
                            </p>
                            <Button onClick={() => setAction('reinstate')} className="mt-4 w-full active:scale-95">
                                Reinstate vendor
                            </Button>
                        </Card>
                    )}

                    <Card className="rounded-2xl bg-gray-50 shadow-none">
                        <p className="flex items-start gap-2 text-xs leading-relaxed text-gray-500">
                            <span aria-hidden="true">🔒</span>
                            Every decision here is written to the staff audit log with your name and a timestamp.
                        </p>
                    </Card>
                </div>
            </div>

            {/* Confirm modal */}
            <Modal
                open={action !== null}
                onClose={() => setAction(null)}
                icon={config ? <config.icon className="h-6 w-6" /> : undefined}
                iconAccent={config?.accent}
                title={config?.title}
                description={modalDescription}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setAction(null)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmAction}
                            disabled={busy}
                            className={
                                config?.danger
                                    ? 'bg-red-600 hover:bg-red-700 focus-visible:outline-red-600 active:scale-95'
                                    : 'active:scale-95'
                            }
                        >
                            {busy ? 'Working…' : config?.confirm}
                        </Button>
                    </>
                }
            >
                {config?.needsReason && (
                    <div>
                        <Label htmlFor="action-reason">Reason</Label>
                        <textarea
                            id="action-reason"
                            rows={4}
                            autoFocus
                            value={reasonForm.data.reason}
                            onChange={(e) => reasonForm.setData('reason', e.target.value)}
                            className="mt-1 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            required
                        />
                        <InputError message={reasonForm.errors.reason} />
                    </div>
                )}
            </Modal>
        </AdminLayout>
    );
}

function Detail({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return (
        <div className="flex items-start gap-2.5">
            <span className="mt-0.5 shrink-0">{icon}</span>
            <span className="min-w-0">
                <dt className="text-xs text-gray-400">{label}</dt>
                <dd className="mt-0.5 truncate font-medium text-gray-900">{value}</dd>
            </span>
        </div>
    );
}
