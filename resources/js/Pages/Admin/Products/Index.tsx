import ProductReviewModal from '@/Components/domain/admin/ProductReviewModal';
import BulkActionBar from '@/Components/ui/BulkActionBar';
import { Card } from '@/Components/ui/Card';
import { Pagination } from '@/Components/ui/Pagination';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import ViewToggle from '@/Components/ui/ViewToggle';
import { useRowSelection } from '@/Hooks/useRowSelection';
import { useViewMode } from '@/Hooks/useViewMode';
import AdminLayout from '@/Layouts/AdminLayout';
import { Paginated } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronRight, PackageSearch } from 'lucide-react';
import { useState } from 'react';

interface ProductRow {
    uuid: string;
    name: string;
    vendor: string;
    category: string;
    priceKobo: number;
    imageUrl: string | null;
    submittedAt: string | null;
}

interface Props {
    products: Paginated<ProductRow>;
    status: string;
    [key: string]: unknown;
}

const statusTabs = ['pending_approval', 'approved', 'rejected', 'delisted'];

export default function AdminProductsIndex() {
    const { products, status } = usePage<Props>().props;
    const [reviewUuid, setReviewUuid] = useState<string | null>(null);
    // Grid by default here: judging a listing is largely judging its photo.
    const { mode, choose } = useViewMode('admin.products', 'grid');

    const selection = useRowSelection(products.data.map((p) => p.uuid));
    const bulk = useForm<{ action: string; uuids: string[]; reason: string }>({
        action: 'approve',
        uuids: [],
        reason: '',
    });

    // Approving something already approved is a no-op, so the bar only offers
    // decisions on the queue where they mean something.
    const canDecide = status === 'pending_approval';

    // Page numbering continues across pages — row 1 of page 2 is #21, not #1.
    const firstIndex = (products.from ?? 1) - 1;

    function runBulk(action: 'approve' | 'reject', reason = '') {
        bulk.transform(() => ({ action, uuids: selection.ids, reason }));
        bulk.post(route('admin.products.bulk'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    return (
        <AdminLayout>
            <Head title="Product approvals" />

            <ProductReviewModal uuid={reviewUuid} onClose={() => setReviewUuid(null)} />

            <PageHeader
                eyebrow="Marketplace operations"
                title="Products"
                description="Review vendor listings before they reach the catalog."
                actions={<ViewToggle mode={mode} onChange={choose} label="products" />}
            />

            <div className="mb-4 flex flex-wrap gap-2">
                {statusTabs.map((tab) => (
                    <Link
                        key={tab}
                        href={route('admin.products.index', { status: tab })}
                        className={
                            tab === status
                                ? 'rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold capitalize text-white shadow-sm shadow-brand-600/25'
                                : 'rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium capitalize text-gray-600 transition hover:border-brand-300 hover:text-brand-700'
                        }
                    >
                        {tab.replace('_', ' ')}
                    </Link>
                ))}
            </div>

            <Reveal>
                <Card className="overflow-hidden p-0">
                    {products.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                                <PackageSearch className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">Nothing in this queue</p>
                            <p className="mt-1 text-sm text-gray-500">Listings with this status will appear here.</p>
                        </div>
                    ) : mode === 'table' ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-sm">
                                <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th className="w-10 pl-5 pr-2 py-3">
                                            <RowCheckbox
                                                checked={selection.allSelected}
                                                indeterminate={selection.someSelected}
                                                onChange={selection.toggleAll}
                                                label="Select all listings on this page"
                                            />
                                        </th>
                                        <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                        <th className="px-5 py-3 font-semibold">Product</th>
                                        <th className="px-5 py-3 font-semibold">Vendor</th>
                                        <th className="px-5 py-3 font-semibold">Category</th>
                                        <th className="px-5 py-3 text-right font-semibold">Price</th>
                                        <th className="px-5 py-3 font-semibold">Submitted</th>
                                        <th className="w-10 px-5 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {products.data.map((product, index) => (
                                        <tr
                                            key={product.uuid}
                                            onClick={() => setReviewUuid(product.uuid)}
                                            className={`group cursor-pointer transition-colors hover:bg-brand-50/50 ${
                                                selection.isSelected(product.uuid) ? 'bg-brand-50/70' : ''
                                            }`}
                                        >
                                            <td className="pl-5 pr-2 py-3.5">
                                                <RowCheckbox
                                                    checked={selection.isSelected(product.uuid)}
                                                    onChange={() => selection.toggle(product.uuid)}
                                                    label={`Select ${product.name}`}
                                                />
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
                                                    <span className="line-clamp-2 font-semibold text-gray-900 group-hover:text-brand-700">
                                                        {product.name}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-5 py-3.5 text-gray-600">{product.vendor}</td>
                                            <td className="px-5 py-3.5 text-gray-500">{product.category}</td>
                                            <td className="px-5 py-3.5 text-right font-bold tabular-nums text-brand-700">
                                                {formatNairaFromKobo(product.priceKobo)}
                                            </td>
                                            <td className="px-5 py-3.5 text-xs text-gray-400">
                                                {product.submittedAt ?? '—'}
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
                        <div className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {products.data.map((product) => (
                                <button
                                    key={product.uuid}
                                    type="button"
                                    onClick={() => setReviewUuid(product.uuid)}
                                    className="group flex flex-col overflow-hidden rounded-xl border border-gray-100 text-left transition hover:border-brand-200 hover:shadow-md hover:shadow-brand-600/5"
                                >
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
                                    <span className="flex flex-1 flex-col p-3.5">
                                        <span className="line-clamp-2 text-sm font-semibold leading-snug text-gray-900 group-hover:text-brand-700">
                                            {product.name}
                                        </span>
                                        <span className="mt-1 truncate text-xs text-gray-500">{product.vendor}</span>
                                        <span className="mt-2 flex items-baseline justify-between gap-2 pt-1">
                                            <span className="font-bold tabular-nums text-brand-700">
                                                {formatNairaFromKobo(product.priceKobo)}
                                            </span>
                                            <span className="truncate text-[11px] text-gray-400">
                                                {product.category}
                                            </span>
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                </Card>
            </Reveal>

            <BulkActionBar
                count={selection.count}
                noun="listing"
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
                                  reasonPlaceholder: 'e.g. Photos do not show the actual item',
                                  run: (reason) => runBulk('reject', reason),
                              },
                          ]
                        : []
                }
            />

            <Pagination links={products.links} />
        </AdminLayout>
    );
}
