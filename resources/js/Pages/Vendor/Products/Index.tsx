import ProductFormModal from '@/Components/domain/vendor/ProductFormModal';
import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import Modal from '@/Components/ui/Modal';
import { Pagination } from '@/Components/ui/Pagination';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import VendorLayout from '@/Layouts/VendorLayout';
import { Paginated } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { PackagePlus, Pencil, Send, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';

interface ProductRow {
    uuid: string;
    name: string;
    category: string;
    priceKobo: number;
    stockQuantity: number;
    status: string;
    rejectionReason: string | null;
    imageUrl: string | null;
    updatedAt: string;
}

interface FeeSettings {
    postingMode: 'free' | 'paid';
    basicFeeNaira: number;
    premiumFeeNaira: number;
    featuredFeeNaira: number;
}

interface Props {
    products: Paginated<ProductRow>;
    counts: Record<string, number>;
    activeStatus: string | null;
    categories: { id: number; name: string }[];
    feeSettings: FeeSettings;
    [key: string]: unknown;
}

const statusTabs = ['draft', 'pending_approval', 'approved', 'rejected', 'delisted'];

export default function VendorProductsIndex() {
    const { products, counts, activeStatus, categories, feeSettings } = usePage<Props>().props;
    const [submitTarget, setSubmitTarget] = useState<ProductRow | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [editUuid, setEditUuid] = useState<string | null>(null);
    const submitForm = useForm({});

    const openCreate = () => {
        setEditUuid(null);
        setFormOpen(true);
    };

    const openEdit = (uuid: string) => {
        setEditUuid(uuid);
        setFormOpen(true);
    };

    // Deep-link from the dashboard "Add product" button opens the modal.
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('new')) openCreate();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const confirmSubmit = () => {
        if (!submitTarget) return;
        submitForm.post(route('vendor.products.submit', { product: submitTarget.uuid }), {
            preserveScroll: true,
            onSuccess: () => setSubmitTarget(null),
        });
    };

    const tab = (label: string, href: string, active: boolean, count?: number) => (
        <Link
            href={href}
            className={
                active
                    ? 'rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-600/25'
                    : 'rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-600 transition hover:border-brand-300 hover:text-brand-700'
            }
        >
            <span className="capitalize">{label}</span>
            {count !== undefined && <span className="ml-1 text-xs opacity-70">({count})</span>}
        </Link>
    );

    return (
        <VendorLayout>
            <Head title="My Products" />

            <PageHeader
                title="My Products"
                description="Drafts, submissions, and everything live on the marketplace."
                actions={
                    <button
                        type="button"
                        onClick={openCreate}
                        className="flex items-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-brand-700 hover:shadow-lg active:scale-95"
                    >
                        <PackagePlus className="h-4 w-4" /> Add product
                    </button>
                }
            />

            <ProductFormModal
                open={formOpen}
                mode={editUuid ? 'edit' : 'create'}
                editUuid={editUuid}
                categories={categories}
                feeSettings={feeSettings}
                onClose={() => setFormOpen(false)}
            />

            <div className="mb-4 flex flex-wrap gap-2">
                {tab('All', route('vendor.products.index'), activeStatus === null)}
                {statusTabs.map((t) =>
                    tab(t.replace('_', ' '), route('vendor.products.index', { status: t }), t === activeStatus, counts[t]),
                )}
            </div>

            <Reveal>
                <Card className="overflow-hidden p-0">
                    {products.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500">
                                <PackagePlus className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">No products here yet</p>
                            <p className="mt-1 text-sm text-gray-500">Add your first product to get started.</p>
                            <button
                                type="button"
                                onClick={openCreate}
                                className="mt-4 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                            >
                                + Add product
                            </button>
                        </div>
                    ) : (
                        <div className="divide-y divide-gray-100">
                            {products.data.map((product) => {
                                const canSubmit = product.status === 'draft' || product.status === 'rejected';
                                return (
                                    <div
                                        key={product.uuid}
                                        className="group flex flex-wrap items-center gap-4 px-5 py-4 transition-colors hover:bg-brand-50/40"
                                    >
                                        {product.imageUrl ? (
                                            <img
                                                src={product.imageUrl}
                                                alt=""
                                                className="h-12 w-12 shrink-0 rounded-xl object-cover ring-1 ring-black/5"
                                            />
                                        ) : (
                                            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-lg">
                                                🛍️
                                            </span>
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-semibold text-gray-900">{product.name}</p>
                                            <p className="truncate text-sm text-gray-500">
                                                {product.category} · {product.stockQuantity} in stock
                                            </p>
                                            {product.status === 'rejected' && product.rejectionReason && (
                                                <p className="mt-0.5 max-w-md truncate text-xs text-red-600">
                                                    {product.rejectionReason}
                                                </p>
                                            )}
                                        </div>
                                        <span className="shrink-0 font-bold text-brand-700">
                                            {formatNairaFromKobo(product.priceKobo)}
                                        </span>
                                        <Badge light tone={statusTone(product.status)}>
                                            {product.status.replace('_', ' ')}
                                        </Badge>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={() => openEdit(product.uuid)}
                                                className="flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:border-brand-300 hover:text-brand-700 active:scale-95"
                                            >
                                                <Pencil className="h-3.5 w-3.5" /> Edit
                                            </button>
                                            {canSubmit && (
                                                <button
                                                    type="button"
                                                    onClick={() => setSubmitTarget(product)}
                                                    className="flex items-center gap-1.5 rounded-full bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700 active:scale-95"
                                                >
                                                    <Send className="h-3.5 w-3.5" /> Submit
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </Card>
            </Reveal>

            <Pagination links={products.links} />

            <Modal
                open={submitTarget !== null}
                onClose={() => setSubmitTarget(null)}
                icon={<Upload className="h-6 w-6" />}
                title="Submit for approval?"
                description={
                    submitTarget
                        ? `"${submitTarget.name}" will be sent to the FirstMarketteam for review. You can’t edit it while it’s pending.`
                        : ''
                }
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setSubmitTarget(null)}>
                            Cancel
                        </Button>
                        <Button onClick={confirmSubmit} disabled={submitForm.processing} className="active:scale-95">
                            {submitForm.processing ? 'Submitting…' : 'Submit for approval'}
                        </Button>
                    </>
                }
            />
        </VendorLayout>
    );
}
