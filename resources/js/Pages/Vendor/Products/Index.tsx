import ProductFormModal from '@/Components/domain/vendor/ProductFormModal';
import { AttributeField } from '@/Components/domain/catalog/DynamicFields';
import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import Modal from '@/Components/ui/Modal';
import { Pagination } from '@/Components/ui/Pagination';
import BulkActionBar from '@/Components/ui/BulkActionBar';
import PageHeader from '@/Components/ui/PageHeader';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import ViewToggle from '@/Components/ui/ViewToggle';
import { useRowSelection } from '@/Hooks/useRowSelection';
import { useViewMode } from '@/Hooks/useViewMode';
import Reveal from '@/Components/ui/Reveal';
import VendorLayout from '@/Layouts/VendorLayout';
import { Paginated } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, Eye, PackagePlus, Pencil, Send, Trash2, Upload } from 'lucide-react';
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
    /** Null until the listing is approved and has a public page. */
    viewUrl: string | null;
    canDelete: boolean;
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
    categories: { id: number; name: string; children: { id: number; name: string }[] }[];
    /** Admin-defined fields, keyed by category id, for the add/edit modal. */
    attributeFieldsByCategory?: Record<string, AttributeField[]>;
    /** Wording for the fields every product has, keyed by system key. */
    builtInFields?: Record<string, { label: string; helpText: string | null }>;
    feeSettings: FeeSettings;
    [key: string]: unknown;
}

const statusTabs = ['draft', 'pending_approval', 'approved', 'rejected', 'delisted'];

/**
 * The per-row controls, identical in the table and on a grid card so the two
 * views offer the same things in the same order.
 */
function RowActions({
    product,
    canSubmit,
    onView,
    onEdit,
    onSubmit,
    onDelete,
}: {
    product: ProductRow;
    canSubmit: boolean;
    onView: () => void;
    onEdit: () => void;
    onSubmit: () => void;
    onDelete: () => void;
}) {
    const icon =
        'rounded-lg p-1.5 transition disabled:cursor-not-allowed disabled:opacity-40';

    return (
        <>
            <button
                type="button"
                onClick={onView}
                className={`${icon} text-gray-400 hover:bg-slate-100 hover:text-gray-700`}
                title="View details"
            >
                <Eye className="h-4 w-4" />
            </button>
            <button
                type="button"
                onClick={onEdit}
                className={`${icon} text-gray-400 hover:bg-slate-100 hover:text-gray-700`}
                title="Edit"
            >
                <Pencil className="h-4 w-4" />
            </button>
            {canSubmit && (
                <button
                    type="button"
                    onClick={onSubmit}
                    className={`${icon} text-emerald-600 hover:bg-emerald-50`}
                    title="Submit for approval"
                >
                    <Send className="h-4 w-4" />
                </button>
            )}
            <button
                type="button"
                onClick={onDelete}
                disabled={!product.canDelete}
                className={`${icon} text-red-500 hover:bg-red-50`}
                title={
                    product.canDelete
                        ? 'Delete listing'
                        : 'Customers are buying or saving towards this — delist it instead'
                }
            >
                <Trash2 className="h-4 w-4" />
            </button>
        </>
    );
}

export default function VendorProductsIndex() {
    const { products, counts, activeStatus, categories, feeSettings, attributeFieldsByCategory, builtInFields } =
        usePage<Props>().props;
    const [submitTarget, setSubmitTarget] = useState<ProductRow | null>(null);
    const [viewTarget, setViewTarget] = useState<ProductRow | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<ProductRow | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [editUuid, setEditUuid] = useState<string | null>(null);
    const submitForm = useForm({});
    const deleteForm = useForm({});

    const confirmDelete = () => {
        if (!deleteTarget) return;
        deleteForm.delete(route('vendor.products.destroy', { product: deleteTarget.uuid }), {
            preserveScroll: true,
            onSuccess: () => setDeleteTarget(null),
        });
    };

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
            key={label}
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

    // Products default to grid — a listing is largely judged by its photo.
    const { mode, choose } = useViewMode('vendor.products', 'grid');

    // Only a draft or a rejected listing can be submitted.
    const submittable = products.data.filter((p) => p.status === 'draft' || p.status === 'rejected');
    const selection = useRowSelection(submittable.map((p) => p.uuid));
    const bulk = useForm<{ uuids: string[] }>({ uuids: [] });
    const firstIndex = (products.from ?? 1) - 1;

    function submitSelected() {
        bulk.transform(() => ({ uuids: selection.ids }));
        bulk.post(route('vendor.products.bulk-submit'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    return (
        <VendorLayout>
            <Head title="My Products" />

            <PageHeader
                title="My Products"
                description="Drafts, submissions, and everything live on the marketplace."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                    <ViewToggle mode={mode} onChange={choose} label="products" />
                    <button
                        type="button"
                        onClick={openCreate}
                        className="flex items-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-brand-700 hover:shadow-lg active:scale-95"
                    >
                        <PackagePlus className="h-4 w-4" /> Add product
                    </button>
                    </div>
                }
            />

            <ProductFormModal
                open={formOpen}
                mode={editUuid ? 'edit' : 'create'}
                editUuid={editUuid}
                categories={categories}
                feeSettings={feeSettings}
                attributeFieldsByCategory={attributeFieldsByCategory}
                builtInFields={builtInFields}
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
                    ) : mode === 'table' ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[820px] text-sm">
                                <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th className="w-10 py-3 pl-5 pr-2">
                                            <RowCheckbox
                                                checked={selection.allSelected}
                                                indeterminate={selection.someSelected}
                                                onChange={selection.toggleAll}
                                                label="Select all listings that can be submitted"
                                            />
                                        </th>
                                        <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                        <th className="px-5 py-3 font-semibold">Product</th>
                                        <th className="px-4 py-3 font-semibold">Category</th>
                                        <th className="px-4 py-3 text-right font-semibold">Price</th>
                                        <th className="px-4 py-3 font-semibold">Stock</th>
                                        <th className="px-4 py-3 font-semibold">Status</th>
                                        <th className="px-5 py-3 text-right font-semibold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {products.data.map((product, index) => {
                                        const canSubmit =
                                            product.status === 'draft' || product.status === 'rejected';

                                        return (
                                            <tr
                                                key={product.uuid}
                                                className={`transition-colors hover:bg-brand-50/40 ${
                                                    selection.isSelected(product.uuid) ? 'bg-brand-50/70' : ''
                                                }`}
                                            >
                                                <td className="py-3.5 pl-5 pr-2">
                                                    {canSubmit && (
                                                        <RowCheckbox
                                                            checked={selection.isSelected(product.uuid)}
                                                            onChange={() => selection.toggle(product.uuid)}
                                                            label={`Select ${product.name}`}
                                                        />
                                                    )}
                                                </td>
                                                <td className="px-2 py-3.5 text-xs tabular-nums text-gray-400">
                                                    {firstIndex + index + 1}
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <div className="flex items-center gap-3">
                                                        {product.imageUrl ? (
                                                            <img
                                                                src={product.imageUrl}
                                                                alt=""
                                                                className="h-9 w-9 shrink-0 rounded-lg object-cover ring-1 ring-black/5"
                                                            />
                                                        ) : (
                                                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100">
                                                                🛍️
                                                            </span>
                                                        )}
                                                        <span className="min-w-0">
                                                            <span className="line-clamp-1 font-semibold text-gray-900">
                                                                {product.name}
                                                            </span>
                                                            {product.status === 'rejected' &&
                                                                product.rejectionReason && (
                                                                    <span className="line-clamp-1 text-xs text-red-600">
                                                                        {product.rejectionReason}
                                                                    </span>
                                                                )}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5 text-gray-600">{product.category}</td>
                                                <td className="px-4 py-3.5 text-right font-bold tabular-nums text-brand-700">
                                                    {formatNairaFromKobo(product.priceKobo)}
                                                </td>
                                                <td className="px-4 py-3.5 tabular-nums text-gray-600">
                                                    {product.stockQuantity}
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <Badge light tone={statusTone(product.status)}>
                                                        {product.status.replace('_', ' ')}
                                                    </Badge>
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <RowActions
                                                            product={product}
                                                            canSubmit={canSubmit}
                                                            onView={() => setViewTarget(product)}
                                                            onEdit={() => openEdit(product.uuid)}
                                                            onSubmit={() => setSubmitTarget(product)}
                                                            onDelete={() => setDeleteTarget(product)}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {products.data.map((product) => {
                                const canSubmit = product.status === 'draft' || product.status === 'rejected';
                                const selected = selection.isSelected(product.uuid);

                                return (
                                    <div
                                        key={product.uuid}
                                        className={`group relative flex flex-col overflow-hidden rounded-xl border transition hover:shadow-md hover:shadow-brand-600/5 ${
                                            selected
                                                ? 'border-brand-400 ring-2 ring-brand-500/20'
                                                : 'border-gray-100 hover:border-brand-200'
                                        }`}
                                    >
                                        <div className="relative">
                                            {product.imageUrl ? (
                                                <img
                                                    src={product.imageUrl}
                                                    alt=""
                                                    className="aspect-square w-full object-cover"
                                                />
                                            ) : (
                                                <span className="flex aspect-square w-full items-center justify-center bg-gray-50 text-3xl">
                                                    🛍️
                                                </span>
                                            )}

                                            {canSubmit && (
                                                <span className="absolute left-2.5 top-2.5 flex h-6 w-6 items-center justify-center rounded-md bg-white/95 shadow-sm ring-1 ring-black/5">
                                                    <RowCheckbox
                                                        checked={selected}
                                                        onChange={() => selection.toggle(product.uuid)}
                                                        label={`Select ${product.name}`}
                                                    />
                                                </span>
                                            )}

                                            <span className="absolute right-2.5 top-2.5">
                                                <Badge light tone={statusTone(product.status)}>
                                                    {product.status.replace('_', ' ')}
                                                </Badge>
                                            </span>
                                        </div>

                                        <div className="flex flex-1 flex-col p-3.5">
                                            <p className="line-clamp-2 text-sm font-semibold leading-snug text-gray-900">
                                                {product.name}
                                            </p>
                                            <p className="mt-1 truncate text-xs text-gray-500">
                                                {product.category} · {product.stockQuantity} in stock
                                            </p>
                                            {product.status === 'rejected' && product.rejectionReason && (
                                                <p className="mt-1 line-clamp-2 text-xs text-red-600">
                                                    {product.rejectionReason}
                                                </p>
                                            )}

                                            <p className="mt-2 font-bold tabular-nums text-brand-700">
                                                {formatNairaFromKobo(product.priceKobo)}
                                            </p>

                                            <div className="mt-3 flex items-center gap-1.5 border-t border-gray-100 pt-3">
                                                <RowActions
                                                    product={product}
                                                    canSubmit={canSubmit}
                                                    onView={() => setViewTarget(product)}
                                                    onEdit={() => openEdit(product.uuid)}
                                                    onSubmit={() => setSubmitTarget(product)}
                                                    onDelete={() => setDeleteTarget(product)}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </Card>
            </Reveal>

            <BulkActionBar
                count={selection.count}
                noun="listing"
                processing={bulk.processing}
                onClear={selection.clear}
                actions={[{ label: 'Submit for approval', tone: 'primary', run: submitSelected }]}
            />

            <Pagination links={products.links} />

            <Modal
                open={submitTarget !== null}
                onClose={() => setSubmitTarget(null)}
                icon={<Upload className="h-6 w-6" />}
                title="Submit for approval?"
                description={
                    submitTarget
                        ? `"${submitTarget.name}" will be sent to the FirstMaket team for review. You can’t edit it while it’s pending.`
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

            <Modal
                open={deleteTarget !== null}
                onClose={() => setDeleteTarget(null)}
                icon={<Trash2 className="h-6 w-6" />}
                iconAccent="bg-red-50 text-red-600"
                title="Delete this listing?"
                description={
                    deleteTarget
                        ? `"${deleteTarget.name}" will be removed from your Vendor Center. This cannot be undone from here.`
                        : ''
                }
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setDeleteTarget(null)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmDelete}
                            disabled={deleteForm.processing}
                            className="bg-red-600 shadow-red-600/25 hover:bg-red-700 hover:shadow-red-600/30 active:scale-95"
                        >
                            {deleteForm.processing ? 'Deleting…' : 'Delete listing'}
                        </Button>
                    </>
                }
            />

            <Modal
                open={viewTarget !== null}
                onClose={() => setViewTarget(null)}
                title={viewTarget?.name ?? ''}
                description={viewTarget ? `${viewTarget.category} · updated ${viewTarget.updatedAt}` : ''}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setViewTarget(null)}>
                            Close
                        </Button>
                        {viewTarget?.viewUrl ? (
                            <a
                                href={viewTarget.viewUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                            >
                                <ExternalLink className="h-4 w-4" /> Open on marketplace
                            </a>
                        ) : (
                            <Button
                                onClick={() => {
                                    const uuid = viewTarget?.uuid;
                                    setViewTarget(null);
                                    if (uuid) openEdit(uuid);
                                }}
                            >
                                Edit listing
                            </Button>
                        )}
                    </>
                }
            >
                {viewTarget && (
                    <div className="flex gap-4">
                        {viewTarget.imageUrl ? (
                            <img
                                src={viewTarget.imageUrl}
                                alt=""
                                className="h-28 w-28 shrink-0 rounded-xl object-cover ring-1 ring-black/5"
                            />
                        ) : (
                            <span className="flex h-28 w-28 shrink-0 items-center justify-center rounded-xl bg-gray-50 text-3xl">
                                🛍️
                            </span>
                        )}
                        <dl className="min-w-0 flex-1 space-y-2 text-sm">
                            <div className="flex justify-between gap-3">
                                <dt className="text-gray-500">Price</dt>
                                <dd className="font-bold tabular-nums text-brand-700">
                                    {formatNairaFromKobo(viewTarget.priceKobo)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-gray-500">In stock</dt>
                                <dd className="font-semibold tabular-nums text-gray-900">
                                    {viewTarget.stockQuantity}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-gray-500">Status</dt>
                                <dd>
                                    <Badge light tone={statusTone(viewTarget.status)}>
                                        {viewTarget.status.replace('_', ' ')}
                                    </Badge>
                                </dd>
                            </div>
                            {viewTarget.status === 'rejected' && viewTarget.rejectionReason && (
                                <div className="rounded-lg bg-red-50 p-2.5 text-xs text-red-700">
                                    {viewTarget.rejectionReason}
                                </div>
                            )}
                            {viewTarget.viewUrl === null && (
                                <p className="text-xs text-gray-400">
                                    Not on the marketplace yet — only approved listings get a public page.
                                </p>
                            )}
                        </dl>
                    </div>
                )}
            </Modal>
        </VendorLayout>
    );
}
