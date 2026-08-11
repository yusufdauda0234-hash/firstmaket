import { ProductCard } from '@/Components/domain/catalog/ProductCard';
import QuickViewModal from '@/Components/domain/catalog/QuickViewModal';
import { Pagination } from '@/Components/ui/Pagination';
import PublicLayout from '@/Layouts/PublicLayout';
import { Select } from '@/Components/ui/Select';
import { Category, Paginated, ProductSummary } from '@/Types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, PackageSearch, SlidersHorizontal, X } from 'lucide-react';
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
    // Searched across sub-categories too, or browsing "Televisions" would
    // lose its heading and filter chip now that children are nested.
    const activeCategory = categories
        .flatMap((c) => [c, ...(c.children ?? [])])
        .find((c) => c.slug === filters.category);
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

    /*
     * Which category groups are expanded.
     *
     * Only the branch being browsed opens by default — everything expanded at
     * once made the rail taller than the screen, which is what stopped it
     * staying put while the results scrolled.
     */
    const branchOfActive = categories.find(
        (c) =>
            c.slug === filters.category ||
            (c.children ?? []).some((child) => child.slug === filters.category),
    );

    const [openCategories, setOpenCategories] = useState<string[]>(
        branchOfActive ? [branchOfActive.slug] : [],
    );

    const toggleCategory = (slug: string) =>
        setOpenCategories((open) =>
            open.includes(slug) ? open.filter((s) => s !== slug) : [...open, slug],
        );

    // Inertia keeps this component mounted between filter clicks, so the open
    // group has to follow the category the visitor actually navigated to.
    useEffect(() => {
        if (branchOfActive) {
            setOpenCategories((open) =>
                open.includes(branchOfActive.slug) ? open : [...open, branchOfActive.slug],
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters.category]);

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
                    {/* Parents collapse over their sub-categories.
                        Expanded all at once the rail ran to several screens,
                        which is what pushed the whole page into scrolling. */}
                    {categories.map((category) => {
                        const children = category.children ?? [];
                        const isOpen = openCategories.includes(category.slug);

                        return (
                            <li key={category.slug}>
                                <div
                                    className={`flex items-center rounded-lg transition ${
                                        filters.category === category.slug
                                            ? 'bg-brand-50 font-semibold text-brand-700'
                                            : 'text-gray-600 hover:bg-gray-50'
                                    }`}
                                >
                                    <Link
                                        href={route('catalog.index', {
                                            category: category.slug,
                                            query: filters.query || undefined,
                                        })}
                                        className="flex min-w-0 flex-1 items-center gap-2 px-3 py-2"
                                    >
                                        <span className="text-base">
                                            {categoryEmoji[category.slug] ?? '🏷️'}
                                        </span>
                                        <span className="truncate">{category.name}</span>
                                    </Link>

                                    {children.length > 0 && (
                                        <button
                                            type="button"
                                            onClick={() => toggleCategory(category.slug)}
                                            aria-expanded={isOpen}
                                            aria-label={`${isOpen ? 'Hide' : 'Show'} ${category.name} sub-categories`}
                                            className="shrink-0 rounded-lg p-2 text-gray-400 transition hover:text-brand-600"
                                        >
                                            <ChevronDown
                                                className={`h-4 w-4 transition-transform ${isOpen ? 'rotate-180' : ''}`}
                                            />
                                        </button>
                                    )}
                                </div>

                                {children.length > 0 && isOpen && (
                                    <ul className="ml-5 border-l border-gray-100 pl-2">
                                        {children.map((child) => (
                                            <li key={child.slug}>
                                                <Link
                                                    href={route('catalog.index', {
                                                        category: child.slug,
                                                        query: filters.query || undefined,
                                                    })}
                                                    className={`block rounded-lg px-3 py-1.5 text-[13px] transition ${
                                                        filters.category === child.slug
                                                            ? 'bg-brand-50 font-semibold text-brand-700'
                                                            : 'text-gray-500 hover:bg-gray-50'
                                                    }`}
                                                >
                                                    {child.name}
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        );
                    })}
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
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                        />
                        <span className="text-gray-300">–</span>
                        <input
                            type="number"
                            value={maxPrice}
                            onChange={(e) => setMaxPrice(e.target.value)}
                            placeholder="Max"
                            min="0"
                            aria-label="Maximum price"
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
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

            {/* Wider than the rest of the storefront on purpose — this is the
                one screen whose job is fitting as many products on screen as
                possible. The header and footer keep their own max-w-7xl.

                An arbitrary value rather than a theme token: a token lives in
                tailwind.config.js, which a already-running `npm run dev` does
                not always pick up, so the cap silently did not exist until the
                dev server was restarted. This is generated from this file. */}
            <div className="mx-auto max-w-[1600px] px-4 pb-12">
                {/* No `items-start` here, deliberately. A sticky element only
                    travels inside its own parent's box, so the <aside> has to
                    stretch to the full height of the results column — shrink
                    it to its content and the rail unsticks the moment the
                    products scroll past the bottom of the category list. */}
                <div className="mt-4 grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
                    {/* Desktop filter rail. Capped to the viewport and
                        scrollable inside, so a long category list still stays
                        put rather than pushing itself off screen. */}
                    <aside className="hidden lg:block">
                        <div className="sticky top-24 max-h-[calc(100vh-7rem)] overflow-y-auto overscroll-contain rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
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
                                    <Select
                                        value={filters.sort}
                                        onChange={(e) => applyFilters({ sort: e.target.value })}
                                        aria-label="Sort products"
                                        className="rounded-full font-medium"
                                    >
                                        <option value="newest">Newest</option>
                                        <option value="price_asc">Price: low to high</option>
                                        <option value="price_desc">Price: high to low</option>
                                    </Select>
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
                            // A fifth column on the widest screens — the extra
                            // width should show more products, not stretch four
                            // cards across it.
                            <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
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
