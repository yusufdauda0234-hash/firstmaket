import ProductReviewModal from '@/Components/domain/admin/ProductReviewModal';
import { Card } from '@/Components/ui/Card';
import { Pagination } from '@/Components/ui/Pagination';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Paginated } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
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

    return (
        <AdminLayout>
            <Head title="Product approvals" />

            <ProductReviewModal uuid={reviewUuid} onClose={() => setReviewUuid(null)} />

            <PageHeader
                eyebrow="Marketplace operations"
                title="Products"
                description="Review vendor listings before they reach the catalog."
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
                    ) : (
                        <div className="divide-y divide-gray-100">
                            {products.data.map((product) => (
                                <button
                                    key={product.uuid}
                                    type="button"
                                    onClick={() => setReviewUuid(product.uuid)}
                                    className="group flex w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-brand-50/50"
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
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold text-gray-900 group-hover:text-brand-700">
                                            {product.name}
                                        </span>
                                        <span className="block truncate text-sm text-gray-500">
                                            {product.vendor} · {product.category}
                                        </span>
                                    </span>
                                    <span className="hidden shrink-0 text-right sm:block">
                                        <span className="block font-bold text-brand-700">
                                            {formatNairaFromKobo(product.priceKobo)}
                                        </span>
                                        <span className="block text-xs text-gray-400">{product.submittedAt ?? '—'}</span>
                                    </span>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-gray-300 transition-transform group-hover:translate-x-1 group-hover:text-brand-500" />
                                </button>
                            ))}
                        </div>
                    )}
                </Card>
            </Reveal>

            <Pagination links={products.links} />
        </AdminLayout>
    );
}
