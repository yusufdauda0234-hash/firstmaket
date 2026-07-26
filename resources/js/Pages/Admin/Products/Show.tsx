import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Sparkles } from 'lucide-react';
import { useState } from 'react';

interface Props {
    product: {
        uuid: string;
        name: string;
        description: string;
        vendor: string;
        category: string;
        priceKobo: number;
        stockQuantity: number;
        status: string;
        rejectionReason: string | null;
        submittedAt: string | null;
        images: { id: number; url: string }[];
        priceHistory: { oldPriceKobo: number; newPriceKobo: number; changedAt: string }[];
        statusEvents: { oldStatus: string | null; newStatus: string; note: string | null; changedAt: string }[];
        aiReview: {
            status: string;
            flags: string[];
            summary: string | null;
            model: string | null;
            reviewedAt: string;
        } | null;
        postingFee: { tier: string; amountKobo: number; paymentStatus: string } | null;
    };
    [key: string]: unknown;
}

export default function AdminProductShow() {
    const { product } = usePage<Props>().props;
    const [confirm, setConfirm] = useState<'approve' | 'reject' | null>(null);
    const approveForm = useForm({});
    const rejectForm = useForm({ reason: '' });

    const [lightbox, setLightbox] = useState<string | null>(null);

    const confirmAction = () => {
        if (confirm === 'approve') {
            approveForm.post(route('admin.products.approve', { product: product.uuid }), {
                preserveScroll: true,
                onSuccess: () => setConfirm(null),
            });
        }
        if (confirm === 'reject') {
            rejectForm.post(route('admin.products.reject', { product: product.uuid }), {
                preserveScroll: true,
                onSuccess: () => setConfirm(null),
            });
        }
    };

    return (
        <AdminLayout>
            <Head title={`Review: ${product.name}`} />

            <PageHeader
                eyebrow="Product review"
                title={product.name}
                backHref={route('admin.products.index')}
                backLabel="Back to queue"
                actions={<Badge tone={statusTone(product.status)}>{product.status.replace('_', ' ')}</Badge>}
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card className="rounded-2xl shadow-sm">
                        <h2 className="mb-4 text-lg font-extrabold text-gray-900">Listing</h2>

                        {product.images.length > 0 && (
                            <div className="mb-5 flex flex-wrap gap-3">
                                {product.images.map((image) => (
                                    <button
                                        key={image.id}
                                        type="button"
                                        onClick={() => setLightbox(image.url)}
                                        className="group overflow-hidden rounded-xl ring-1 ring-black/5 transition hover:ring-2 hover:ring-brand-400"
                                    >
                                        <img
                                            src={image.url}
                                            alt=""
                                            className="h-24 w-24 object-cover transition-transform duration-300 group-hover:scale-110"
                                        />
                                    </button>
                                ))}
                            </div>
                        )}

                        <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-xs text-gray-400">Vendor</dt>
                                <dd className="mt-0.5 font-medium text-gray-900">{product.vendor}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-400">Category</dt>
                                <dd className="mt-0.5 font-medium text-gray-900">{product.category}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-400">Vendor price (read-only)</dt>
                                <dd className="mt-0.5 text-xl font-extrabold text-brand-700">
                                    {formatNairaFromKobo(product.priceKobo)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-400">Stock</dt>
                                <dd className="mt-0.5 font-medium text-gray-900">{product.stockQuantity}</dd>
                            </div>
                            <div className="sm:col-span-2">
                                <dt className="text-xs text-gray-400">Description</dt>
                                <dd className="mt-1 whitespace-pre-line leading-relaxed text-gray-700">
                                    {product.description}
                                </dd>
                            </div>
                        </dl>
                    </Card>

                    <Card className="rounded-2xl shadow-sm">
                        <h2 className="mb-4 text-lg font-extrabold text-gray-900">History</h2>
                        <div className="grid gap-6 sm:grid-cols-2">
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wide text-gray-400">Status changes</h3>
                                <ol className="mt-3 space-y-3">
                                    {product.statusEvents.map((event, index) => (
                                        <li key={index} className="relative flex gap-3 text-sm">
                                            <span className="mt-1 flex h-2 w-2 shrink-0 rounded-full bg-brand-400 ring-4 ring-brand-50" />
                                            <span>
                                                <span className="capitalize text-gray-700">
                                                    {(event.oldStatus ?? 'new').replace('_', ' ')} →{' '}
                                                    {event.newStatus.replace('_', ' ')}
                                                </span>
                                                {event.note && <span className="text-gray-500"> — {event.note}</span>}
                                                <span className="block text-xs text-gray-400">{event.changedAt}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wide text-gray-400">Price changes</h3>
                                {product.priceHistory.length === 0 ? (
                                    <p className="mt-3 text-sm text-gray-500">No price changes.</p>
                                ) : (
                                    <ul className="mt-3 space-y-2 text-sm">
                                        {product.priceHistory.map((entry, index) => (
                                            <li key={index} className="text-gray-700">
                                                {formatNairaFromKobo(entry.oldPriceKobo)} →{' '}
                                                <span className="font-semibold">
                                                    {formatNairaFromKobo(entry.newPriceKobo)}
                                                </span>
                                                <span className="block text-xs text-gray-400">{entry.changedAt}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    </Card>
                </div>

                <div className="space-y-6">
                    {product.status === 'pending_approval' && (
                        <Card className="rounded-2xl border-brand-100 bg-gradient-to-br from-brand-50/60 to-white shadow-sm">
                            <h2 className="text-lg font-extrabold text-gray-900">Decision</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Approving publishes this listing at the vendor's price.
                            </p>
                            <Button onClick={() => setConfirm('approve')} className="mt-4 w-full active:scale-95">
                                <CheckCircle2 className="mr-2 h-4 w-4" /> Approve listing
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setConfirm('reject')}
                                className="mt-2 w-full border-red-200 text-red-600 hover:bg-red-50 active:scale-95"
                            >
                                Reject listing
                            </Button>
                        </Card>
                    )}

                    <Card className="rounded-2xl shadow-sm">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
                            <Sparkles className="h-4 w-4 text-violet-500" /> AI listing review
                        </h2>
                        {product.aiReview === null ? (
                            <p className="text-sm text-gray-500">No AI review yet — it queues automatically on submission. Review manually for now.</p>
                        ) : (
                            <div className="space-y-2 text-sm">
                                <p className="flex items-center gap-2">
                                    <Badge tone={statusTone(product.aiReview.status)}>{product.aiReview.status}</Badge>
                                    {product.aiReview.model && (
                                        <span className="text-xs text-gray-400">
                                            {product.aiReview.model} · {product.aiReview.reviewedAt}
                                        </span>
                                    )}
                                </p>
                                {product.aiReview.flags.length > 0 && (
                                    <ul className="flex flex-wrap gap-1.5">
                                        {product.aiReview.flags.map((flag) => (
                                            <li
                                                key={flag}
                                                className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700"
                                            >
                                                {flag}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {product.aiReview.summary && <p className="text-gray-700">{product.aiReview.summary}</p>}
                                <p className="text-xs text-gray-400">Advisory only — the decision is yours.</p>
                            </div>
                        )}
                    </Card>

                    {product.postingFee && (
                        <Card className="rounded-2xl shadow-sm">
                            <h2 className="mb-3 text-sm font-bold uppercase tracking-wide text-gray-400">Posting fee</h2>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-gray-500">Tier</dt>
                                    <dd className="font-medium capitalize text-gray-900">{product.postingFee.tier}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-gray-500">Amount</dt>
                                    <dd className="font-medium text-gray-900">
                                        {formatNairaFromKobo(product.postingFee.amountKobo)}
                                    </dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-gray-500">Payment</dt>
                                    <dd>
                                        <Badge
                                            tone={product.postingFee.paymentStatus === 'paid' ? 'success' : 'warning'}
                                        >
                                            {product.postingFee.paymentStatus.replace('_', ' ')}
                                        </Badge>
                                    </dd>
                                </div>
                            </dl>
                        </Card>
                    )}

                    {product.status === 'rejected' && product.rejectionReason && (
                        <Card className="rounded-2xl border-red-100 bg-red-50 shadow-sm">
                            <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-red-500">
                                Rejection reason
                            </h2>
                            <p className="text-sm text-red-700">{product.rejectionReason}</p>
                        </Card>
                    )}
                </div>
            </div>

            {/* Approve / reject confirm modal */}
            <Modal
                open={confirm !== null}
                onClose={() => setConfirm(null)}
                icon={confirm === 'approve' ? <CheckCircle2 className="h-6 w-6" /> : <AlertTriangle className="h-6 w-6" />}
                iconAccent={confirm === 'approve' ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'}
                title={confirm === 'approve' ? 'Approve this listing?' : 'Reject this listing?'}
                description={
                    confirm === 'approve'
                        ? 'It goes live on the customer catalog immediately at the vendor’s price.'
                        : 'Add a clear reason — the vendor sees it and can fix and resubmit.'
                }
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirm(null)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmAction}
                            disabled={approveForm.processing || rejectForm.processing}
                            className={
                                confirm === 'reject'
                                    ? 'bg-red-600 hover:bg-red-700 focus-visible:outline-red-600 active:scale-95'
                                    : 'active:scale-95'
                            }
                        >
                            {approveForm.processing || rejectForm.processing
                                ? 'Working…'
                                : confirm === 'approve'
                                  ? 'Approve listing'
                                  : 'Reject listing'}
                        </Button>
                    </>
                }
            >
                {confirm === 'reject' && (
                    <div>
                        <Label htmlFor="reason">Reason shown to the vendor</Label>
                        <textarea
                            id="reason"
                            rows={4}
                            autoFocus
                            value={rejectForm.data.reason}
                            onChange={(e) => rejectForm.setData('reason', e.target.value)}
                            className="mt-1 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            required
                        />
                        <InputError message={rejectForm.errors.reason} />
                    </div>
                )}
            </Modal>

            {/* Image lightbox */}
            <Modal open={lightbox !== null} onClose={() => setLightbox(null)} size="xl">
                {lightbox && <img src={lightbox} alt="" className="mx-auto max-h-[75vh] w-auto rounded-xl" />}
            </Modal>
        </AdminLayout>
    );
}
