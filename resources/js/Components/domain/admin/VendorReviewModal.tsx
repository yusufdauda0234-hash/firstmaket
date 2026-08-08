import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import { router } from '@inertiajs/react';
import { CheckCircle2, Download, KeyRound, Mail, MapPin, Phone, RotateCcw, ShieldOff, Store } from 'lucide-react';
import { useEffect, useState } from 'react';

interface VendorDetail {
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
    documents: { uuid: string; type: string; originalName: string; uploadedAt: string }[];
}

type Action = 'approve' | 'reject' | 'suspend' | 'reinstate';

const docIcon: Record<string, string> = { cac: '🏢', identity: '🪪', address_proof: '📍', other: '📄' };

/**
 * View-only quick review of a vendor in a modal (opened from the vendor list),
 * with the same approve / reject / suspend / reinstate actions the full page
 * offers. Details are fetched lazily when a uuid is set.
 */
export default function VendorReviewModal({
    uuid,
    canApprove,
    canSuspend,
    onClose,
}: {
    uuid: string | null;
    canApprove: boolean;
    canSuspend: boolean;
    onClose: () => void;
}) {
    const [vendor, setVendor] = useState<VendorDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const [action, setAction] = useState<Action | null>(null);
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState('');
    const [busy, setBusy] = useState(false);
    const [sendingReset, setSendingReset] = useState(false);

    /**
     * Email the vendor a code to set a new password.
     *
     * Closes the modal on the way out so the flash message is visible — it is
     * rendered by the page underneath, not in here.
     */
    const sendPasswordReset = () => {
        if (!vendor) return;

        setSendingReset(true);
        router.post(
            route('admin.vendors.password-reset', vendor.uuid),
            {},
            {
                preserveScroll: true,
                onFinish: () => setSendingReset(false),
                onSuccess: () => onClose(),
            },
        );
    };

    useEffect(() => {
        if (!uuid) return;
        setVendor(null);
        setAction(null);
        setReason('');
        setReasonError('');
        setLoading(true);
        const controller = new AbortController();
        fetch(route('admin.vendors.details', uuid), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('failed'))))
            .then((body: { vendor: VendorDetail }) => setVendor(body.vendor))
            .catch(() => {
                /* aborted or failed */
            })
            .finally(() => setLoading(false));
        return () => controller.abort();
    }, [uuid]);

    if (!uuid) return null;

    const needsReason = action === 'reject' || action === 'suspend';

    const runAction = () => {
        if (!vendor || !action) return;
        if (needsReason && reason.trim() === '') {
            setReasonError('A reason is required.');
            return;
        }
        setBusy(true);
        const routes: Record<Action, string> = {
            approve: route('admin.vendors.approve', vendor.uuid),
            reject: route('admin.vendors.reject', vendor.uuid),
            suspend: route('admin.vendors.suspend', vendor.uuid),
            reinstate: route('admin.vendors.reinstate', vendor.uuid),
        };
        router.post(
            routes[action],
            needsReason ? { reason } : {},
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (errors) => setReasonError(errors.reason ?? 'Action failed.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Modal
            open={uuid !== null}
            onClose={onClose}
            size="xl"
            title={vendor?.businessName ?? 'Vendor review'}
            description={vendor ? undefined : 'Loading vendor…'}
        >
            {loading || !vendor ? (
                <div className="space-y-3">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="h-14 animate-pulse rounded-xl bg-gray-100" />
                    ))}
                </div>
            ) : (
                <div className="space-y-5">
                    <div className="flex items-center gap-2">
                        <Badge tone={statusTone(vendor.status)}>{vendor.status}</Badge>
                        <span className="text-xs text-gray-400">Registered {vendor.registeredAt}</span>
                    </div>

                    {/* Details */}
                    <dl className="grid gap-x-6 gap-y-4 rounded-2xl border border-gray-100 bg-gray-50/60 p-4 text-sm sm:grid-cols-2">
                        <Detail icon={<Store className="h-4 w-4 text-gray-400" />} label="Contact person" value={vendor.contactName} />
                        <Detail icon={<Mail className="h-4 w-4 text-gray-400" />} label="Email" value={vendor.email} />
                        <Detail icon={<Phone className="h-4 w-4 text-gray-400" />} label="Phone" value={vendor.phone} />
                        <Detail icon={<MapPin className="h-4 w-4 text-gray-400" />} label="Address" value={vendor.address ?? '—'} />
                        {vendor.approvedBy && (
                            <Detail
                                icon={<CheckCircle2 className="h-4 w-4 text-emerald-500" />}
                                label="Approved by"
                                value={`${vendor.approvedBy} · ${vendor.approvedAt}`}
                            />
                        )}
                    </dl>

                    {vendor.rejectionReason && (
                        <div className="rounded-xl border border-red-100 bg-red-50 px-4 py-3">
                            <p className="text-xs font-bold uppercase tracking-wide text-red-500">Reason on file</p>
                            <p className="mt-1 text-sm text-red-700">{vendor.rejectionReason}</p>
                        </div>
                    )}

                    {/* Documents */}
                    <div>
                        <h3 className="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">Documents</h3>
                        {vendor.documents.length === 0 ? (
                            <p className="rounded-xl bg-gray-50 px-4 py-4 text-center text-sm text-gray-400">
                                No documents uploaded.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {vendor.documents.map((doc) => (
                                    <li
                                        key={doc.uuid}
                                        className="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-2.5"
                                    >
                                        <span className="flex min-w-0 items-center gap-3">
                                            <span className="text-base">{docIcon[doc.type] ?? '📄'}</span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-semibold capitalize text-gray-900">
                                                    {doc.type.replace('_', ' ')}
                                                </span>
                                                <span className="block truncate text-xs text-gray-400">{doc.originalName}</span>
                                            </span>
                                        </span>
                                        <a
                                            href={route('admin.documents.download', doc.uuid)}
                                            className="flex shrink-0 items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1.5 text-xs font-semibold text-brand-600 transition hover:border-brand-300 hover:bg-brand-50"
                                        >
                                            <Download className="h-3.5 w-3.5" /> Download
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {/* Reason field when an action needs it */}
                    {needsReason && (
                        <div>
                            <Label htmlFor="vendor-reason">
                                {action === 'reject' ? 'Rejection reason' : 'Suspension reason'}
                            </Label>
                            <textarea
                                id="vendor-reason"
                                rows={3}
                                autoFocus
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                className="mt-1 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            />
                            <InputError message={reasonError} />
                        </div>
                    )}

                    {/* Actions */}
                    <div className="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4">
                        {action === null ? (
                            <>
                                <a
                                    href={route('admin.vendors.show', vendor.uuid)}
                                    className="mr-auto self-center text-sm font-medium text-gray-500 hover:text-brand-600"
                                >
                                    Open full page →
                                </a>
                                {/* Account recovery. Offered on any live account,
                                    not just pending ones — the seller who needs
                                    it is usually one who cannot get in. */}
                                {canApprove && vendor.status !== 'rejected' && (
                                    <Button
                                        variant="secondary"
                                        onClick={sendPasswordReset}
                                        disabled={sendingReset}
                                    >
                                        <KeyRound className="mr-2 h-4 w-4" />
                                        {sendingReset ? 'Sending…' : 'Email password link'}
                                    </Button>
                                )}
                                {vendor.status === 'pending' && canApprove && (
                                    <>
                                        <Button
                                            variant="secondary"
                                            className="border-red-200 text-red-600 hover:bg-red-50"
                                            onClick={() => setAction('reject')}
                                        >
                                            Reject
                                        </Button>
                                        <Button onClick={() => setAction('approve')}>
                                            <CheckCircle2 className="mr-2 h-4 w-4" /> Approve
                                        </Button>
                                    </>
                                )}
                                {vendor.status === 'approved' && canSuspend && (
                                    <Button
                                        variant="secondary"
                                        className="border-red-200 text-red-600 hover:bg-red-50"
                                        onClick={() => setAction('suspend')}
                                    >
                                        <ShieldOff className="mr-2 h-4 w-4" /> Suspend
                                    </Button>
                                )}
                                {vendor.status === 'suspended' && canSuspend && (
                                    <Button onClick={() => setAction('reinstate')}>
                                        <RotateCcw className="mr-2 h-4 w-4" /> Reinstate
                                    </Button>
                                )}
                            </>
                        ) : (
                            <>
                                <Button variant="ghost" onClick={() => { setAction(null); setReason(''); setReasonError(''); }}>
                                    Back
                                </Button>
                                <Button
                                    onClick={runAction}
                                    disabled={busy}
                                    className={
                                        action === 'reject' || action === 'suspend'
                                            ? 'bg-red-600 hover:bg-red-700 focus-visible:outline-red-600'
                                            : ''
                                    }
                                >
                                    {busy ? 'Working…' : `Confirm ${action}`}
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            )}
        </Modal>
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
