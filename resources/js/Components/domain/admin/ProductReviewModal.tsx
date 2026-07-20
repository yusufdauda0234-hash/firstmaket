import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import { formatNairaFromKobo } from '@/Utils/money';
import { router } from '@inertiajs/react';
import { CheckCircle2, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';

interface ProductDetail {
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
    aiReview: { status: string; flags: string[]; summary: string | null; model: string | null; reviewedAt: string } | null;
    postingFee: { tier: string; amountKobo: number; paymentStatus: string } | null;
}

/**
 * View-only quick review of a product in a modal (opened from the product
 * list), with approve / reject actions. Details fetched lazily on open.
 */
export default function ProductReviewModal({ uuid, onClose }: { uuid: string | null; onClose: () => void }) {
    const [product, setProduct] = useState<ProductDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const [mode, setMode] = useState<'view' | 'reject'>('view');
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!uuid) return;
        setProduct(null);
        setMode('view');
        setReason('');
        setError('');
        setLoading(true);
        const controller = new AbortController();
        fetch(route('admin.products.details', uuid), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('failed'))))
            .then((body: { product: ProductDetail }) => setProduct(body.product))
            .catch(() => {
                /* aborted or failed */
            })
            .finally(() => setLoading(false));
        return () => controller.abort();
    }, [uuid]);

    if (!uuid) return null;

    const approve = () => {
        if (!product) return;
        setBusy(true);
        router.post(route('admin.products.approve', { product: product.uuid }), {}, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onFinish: () => setBusy(false),
        });
    };

    const reject = () => {
        if (!product) return;
        if (reason.trim() === '') {
            setError('A reason is required.');
            return;
        }
        setBusy(true);
        router.post(route('admin.products.reject', { product: product.uuid }), { reason }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => setError(errors.reason ?? 'Action failed.'),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Modal open={uuid !== null} onClose={onClose} size="xl" title={product?.name ?? 'Product review'}>
            {loading || !product ? (
                <div className="space-y-3">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="h-14 animate-pulse rounded-xl bg-gray-100" />
                    ))}
                </div>
            ) : (
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={statusTone(product.status)}>{product.status.replace('_', ' ')}</Badge>
                        <span className="text-xs text-gray-400">
                            {product.vendor} · {product.category}
                            {product.submittedAt ? ` · ${product.submittedAt}` : ''}
                        </span>
                    </div>

                    {product.images.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {product.images.map((img) => (
                                <a key={img.id} href={img.url} target="_blank" rel="noreferrer">
                                    <img
                                        src={img.url}
                                        alt=""
                                        className="h-20 w-20 rounded-xl object-cover ring-1 ring-black/5 transition hover:ring-2 hover:ring-brand-400"
                                    />
                                </a>
                            ))}
                        </div>
                    )}

                    <dl className="grid gap-x-6 gap-y-3 rounded-2xl border border-gray-100 bg-gray-50/60 p-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-gray-400">Vendor price (read-only)</dt>
                            <dd className="mt-0.5 text-lg font-extrabold text-brand-700">
                                {formatNairaFromKobo(product.priceKobo)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-400">Stock</dt>
                            <dd className="mt-0.5 font-medium text-gray-900">{product.stockQuantity}</dd>
                        </div>
                        {product.postingFee && (
                            <div className="sm:col-span-2">
                                <dt className="text-xs text-gray-400">Posting fee</dt>
                                <dd className="mt-0.5 flex flex-wrap items-center gap-2 font-medium capitalize text-gray-900">
                                    {product.postingFee.tier} tier · {formatNairaFromKobo(product.postingFee.amountKobo)}
                                    <Badge tone={product.postingFee.paymentStatus === 'paid' ? 'success' : 'warning'}>
                                        {product.postingFee.paymentStatus.replace('_', ' ')}
                                    </Badge>
                                </dd>
                            </div>
                        )}
                        <div className="sm:col-span-2">
                            <dt className="text-xs text-gray-400">Description</dt>
                            <dd className="mt-0.5 whitespace-pre-line leading-relaxed text-gray-700">
                                {product.description}
                            </dd>
                        </div>
                    </dl>

                    {/* AI review */}
                    <div className="rounded-2xl border border-gray-100 p-4">
                        <h3 className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-gray-400">
                            <Sparkles className="h-4 w-4 text-violet-500" /> AI listing review
                        </h3>
                        {product.aiReview === null ? (
                            <p className="text-sm text-gray-500">No AI review yet — review manually.</p>
                        ) : (
                            <div className="space-y-1.5 text-sm">
                                <Badge tone={statusTone(product.aiReview.status)}>{product.aiReview.status}</Badge>
                                {product.aiReview.summary && <p className="text-gray-700">{product.aiReview.summary}</p>}
                            </div>
                        )}
                    </div>

                    {product.rejectionReason && (
                        <div className="rounded-xl border border-red-100 bg-red-50 px-4 py-3">
                            <p className="text-xs font-bold uppercase tracking-wide text-red-500">Rejection reason</p>
                            <p className="mt-1 text-sm text-red-700">{product.rejectionReason}</p>
                        </div>
                    )}

                    {mode === 'reject' && (
                        <div>
                            <Label htmlFor="product-reason">Reason shown to the vendor</Label>
                            <textarea
                                id="product-reason"
                                rows={3}
                                autoFocus
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                className="mt-1 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            />
                            <InputError message={error} />
                        </div>
                    )}

                    <div className="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4">
                        {mode === 'view' ? (
                            <>
                                <Button variant="ghost" onClick={onClose}>
                                    Close
                                </Button>
                                {product.status === 'pending_approval' && (
                                    <>
                                        <Button
                                            variant="secondary"
                                            className="border-red-200 text-red-600 hover:bg-red-50"
                                            onClick={() => setMode('reject')}
                                        >
                                            Reject
                                        </Button>
                                        <Button onClick={approve} disabled={busy}>
                                            <CheckCircle2 className="mr-2 h-4 w-4" /> {busy ? 'Working…' : 'Approve'}
                                        </Button>
                                    </>
                                )}
                            </>
                        ) : (
                            <>
                                <Button variant="ghost" onClick={() => { setMode('view'); setError(''); }}>
                                    Back
                                </Button>
                                <Button
                                    onClick={reject}
                                    disabled={busy}
                                    className="bg-red-600 hover:bg-red-700 focus-visible:outline-red-600"
                                >
                                    {busy ? 'Working…' : 'Confirm reject'}
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            )}
        </Modal>
    );
}
