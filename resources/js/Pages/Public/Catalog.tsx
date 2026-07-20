import { ProductCard } from '@/Components/domain/catalog/ProductCard';
import QuickViewModal from '@/Components/domain/catalog/QuickViewModal';
import { Pagination } from '@/Components/ui/Pagination';
import PublicLayout from '@/Layouts/PublicLayout';
import { Category, Paginated, ProductSummary } from '@/Types';
import { Head, Link, router } from '@inertiajs/react';
import { PackageSearch, SlidersHorizontal, X } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface CatalogProps {
    products: Paginated<ProductSummary>;
    categories: Category[];
    filters: {
        query: string;
        category: string;
        sort: string;
        minPrice: number | null;
        maxPrice: number | null;
    };
}

const categoryEmoji: Record<string, string> = {
    electronics: '📺',
    'home-appliances': '🧊',
    'solar-equipment': '🔆',
    furniture: '🛋️',
    fashion: '👗',
    'business-equipment': '🖨️',
};

/**
 * Public approved-products catalog (Sprint 3): search, category and price
 * filters, sorting, pagination — modern marketplace styling with a sticky
 * filter rail on desktop and a slide-over filter drawer on mobile.
 */
export default function Catalog({ products, categories, filters }: CatalogProps) {
    const activeCategory = categories.find((c) => c.slug === filters.category);
    const [minPrice, setMinPrice] = useState(filters.minPrice?.toString() ?? '');
    const [maxPrice, setMaxPrice] = useState(filters.maxPrice?.toString() ?? '');
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [quickView, setQuickView] = useState<ProductSummary | null>(null);

    useEffect(() => setDrawerOpen(false), [products]);

    const heading = activeCategory
        ? activeCategory.name
        : filters.query
          ? `Results for “${filters.query}”`
          : 'All products';

    function applyFilters(overrides: Record<string, string | undefined>) {
        router.get(
            route('catalog.index'),
            {
                query: filters.query || undefined,
                category: filters.category || undefined,
                sort: filters.sort !== 'newest' ? filters.sort : undefined,
                min_price: minPrice || undefined,
                max_price: maxPrice || undefined,
                ...overrides,
            },
            { preserveScroll: true, preserveState: true },
        );
    }

    function applyPrice(e: FormEvent) {
        e.preventDefault();
        applyFilters({});
    }

    const hasActiveFilters =
        Boolean(filters.category) ||
        Boolean(filters.query) ||
        filters.minPrice !== null ||
        filters.maxPrice !== null;

    // ── Filter rail (shared by desktop sidebar and mobile drawer) ──
    const filterBody = (
        <>
            <div>
                <h2 className="text-xs font-bold uppercase tracking-wide text-gray-400">Category</h2>
                <ul className="mt-3 space-y-1 text-sm">
                    <li>
                        <Link
                            href={route('catalog.index', { query: filters.query || undefined })}
                            className={`flex items-center gap-2 rounded-lg px-3 py-2 transition ${
                                !filters.category
                                    ? 'bg-brand-50 font-semibold text-brand-700'
                                    : 'text-gray-600 hover:bg-gray-50'
                            }`}
                        >
                            <span className="text-base">🛍️</span> All products
                        </Link>
                    </li>
                    {categories.map((category) => (
                        <li key={category.slug}>
                            <Link
                                href={route('catalog.index', {
                                    category: category.slug,
                                    query: filters.query || undefined,
                                })}
                                className={`flex items-center gap-2 rounded-lg px-3 py-2 transition ${
                                    filters.category === category.slug
                                        ? 'bg-brand-50 font-semibold text-brand-700'
                                        : 'text-gray-600 hover:bg-gray-50'
                                }`}
                            >
                                <span className="text-base">{categoryEmoji[category.slug] ?? '🏷️'}</span>
                                {category.name}
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>

            <div className="mt-6 border-t border-gray-100 pt-5">
                <h2 className="text-xs font-bold uppercase tracking-wide text-gray-400">Price range (₦)</h2>
                <form onSubmit={applyPrice} className="mt-3 space-y-2">
                    <div className="flex items-center gap-2">
                        <input
                            type="number"
                            value={minPrice}
                            onChange={(e) => setMinPrice(e.target.value)}
                            placeholder="Min"
                            min="0"
                            aria-label="Minimum price"
                            className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                        />
                        <span className="text-gray-300">–</span>
                        <input
                            type="number"
                            value={maxPrice}
                            onChange={(e) => setMaxPrice(e.target.value)}
                            placeholder="Max"
                            min="0"
                            aria-label="Maximum price"
                            className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                        />
                    </div>
                    <button
                        type="submit"
                        className="w-full rounded-full bg-brand-600 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 active:scale-95"
                    >
                        Apply price
                    </button>
                </form>
            </div>

            {hasActiveFilters && (
                <Link
                    href={route('catalog.index')}
                    className="mt-5 flex items-center justify-center gap-1.5 rounded-full border border-gray-200 py-2 text-sm font-medium text-gray-600 transition hover:border-red-200 hover:text-red-600"
                >
                    <X className="h-3.5 w-3.5" /> Clear all filters
                </Link>
            )}
        </>
    );

    return (
        <PublicLayout categories={categories}>
            <Head title={heading} />

            {quickView && (
                <QuickViewModal
                    product={quickView}
                    pool={products.data}
                    onSwitch={setQuickView}
                    onClose={() => setQuickView(null)}
                />
            )}

            <div className="mx-auto max-w-7xl px-4 pb-12">
                <div className="mt-4 grid gap-6 lg:grid-cols-[240px_1fr]">
                    {/* Desktop filter rail */}
                    <aside className="hidden lg:block">
                        <div className="sticky top-24 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                            {filterBody}
                        </div>
                    </aside>

                    {/* Results */}
                    <div>
                        {/* Toolbar */}
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-100 bg-white px-4 py-3 shadow-sm">
                            <div>
                                <h1 className="text-lg font-extrabold tracking-tight text-gray-900">{heading}</h1>
                                <p className="text-sm text-gray-500">
                                    {products.total} {products.total === 1 ? 'product' : 'products'}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setDrawerOpen(true)}
                                    className="flex items-center gap-1.5 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition hover:border-brand-300 hover:text-brand-700 lg:hidden"
                                >
                                    <SlidersHorizontal className="h-4 w-4" /> Filters
                                </button>
                                <label className="flex items-center gap-2 text-sm text-gray-600">
                                    <span className="hidden sm:inline">Sort</span>
                                    <select
                                        value={filters.sort}
                                        onChange={(e) => applyFilters({ sort: e.target.value })}
                                        className="rounded-full border border-gray-200 px-3 py-2 text-sm font-medium focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                                    >
                                        <option value="newest">Newest</option>
                                        <option value="price_asc">Price: low to high</option>
                                        <option value="price_desc">Price: high to low</option>
                                    </select>
                                </label>
                            </div>
                        </div>

                        {/* Active filter chips */}
                        {hasActiveFilters && (
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                {filters.query && (
                                    <FilterChip
                                        label={`“${filters.query}”`}
                                        href={route('catalog.index', { category: filters.category || undefined })}
                                    />
                                )}
                                {activeCategory && (
                                    <FilterChip
                                        label={activeCategory.name}
                                        href={route('catalog.index', { query: filters.query || undefined })}
                                    />
                                )}
                                {(filters.minPrice !== null || filters.maxPrice !== null) && (
                                    <FilterChip
                                        label={`₦${filters.minPrice ?? 0} – ₦${filters.maxPrice ?? '∞'}`}
                                        onClear={() => {
                                            setMinPrice('');
                                            setMaxPrice('');
                                            applyFilters({ min_price: undefined, max_price: undefined });
                                        }}
                                    />
                                )}
                            </div>
                        )}

                        {/* Grid / empty state */}
                        {products.data.length === 0 ? (
                            <div className="mt-4 flex flex-col items-center rounded-2xl border border-gray-100 bg-white px-6 py-16 text-center shadow-sm">
                                <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                                    <PackageSearch className="h-8 w-8" />
                                </span>
                                <h2 className="mt-4 text-lg font-bold text-gray-900">No products found</h2>
                                <p className="mx-auto mt-1 max-w-md text-sm text-gray-600">
                                    Try a different search or category — verified vendors add new products all the
                                    time.
                                </p>
                                {hasActiveFilters && (
                                    <Link
                                        href={route('catalog.index')}
                                        className="mt-4 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                                    >
                                        Clear filters
                                    </Link>
                                )}
                            </div>
                        ) : (
                            <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                                {products.data.map((product) => (
                                    <ProductCard key={product.uuid} product={product} onQuickView={setQuickView} />
                                ))}
                            </div>
                        )}

                        <Pagination links={products.links} />
                    </div>
                </div>
            </div>

            {/* Mobile filter drawer */}
            {drawerOpen && (
                <div className="fixed inset-0 z-[70] lg:hidden">
                    <div
                        className="animate-fadeIn absolute inset-0 bg-slate-900/60 backdrop-blur-sm"
                        onClick={() => setDrawerOpen(false)}
                        aria-hidden="true"
                    />
                    <div className="animate-slideInRight absolute inset-y-0 left-0 w-80 max-w-[85vw] overflow-y-auto bg-white p-5 shadow-2xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-lg font-extrabold text-gray-900">Filters</h2>
                            <button
                                type="button"
                                onClick={() => setDrawerOpen(false)}
                                aria-label="Close filters"
                                className="rounded-full bg-gray-100 p-2 text-gray-500 transition hover:bg-gray-200 active:scale-90"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        {filterBody}
                    </div>
                </div>
            )}
        </PublicLayout>
    );
}

function FilterChip({ label, href, onClear }: { label: string; href?: string; onClear?: () => void }) {
    const className =
        'inline-flex items-center gap-1.5 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 transition hover:bg-brand-100';
    const content = (
        <>
            {label}
            <X className="h-3 w-3" />
        </>
    );
    if (href) {
        return (
            <Link href={href} className={className} preserveScroll>
                {content}
            </Link>
        );
    }
    return (
        <button type="button" onClick={onClear} className={className}>
            {content}
        </button>
    );
}
