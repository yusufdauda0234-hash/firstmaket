import PublicLayout from '@/Layouts/PublicLayout';
import { useCompare, MAX_COMPARE } from '@/Hooks/useCompare';
import { useMoney } from '@/Hooks/useI18n';
import { ProductSummary } from '@/Types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Check, GitCompareArrows, Package, Plus, Star, X } from 'lucide-react';
import { ReactNode, useState } from 'react';

interface ComparedProduct extends ProductSummary {
    categoryName: string;
    vendorName: string;
    description: string;
}

/** One admin-defined field, aligned across the compared products. */
interface SpecRow {
    key: string;
    label: string;
    /** Keyed by product uuid; null where that product left the field blank. */
    values: Record<string, string | null>;
    same: boolean;
}

interface Props {
    products: ComparedProduct[];
    specRows: SpecRow[];
}

const MAX_PRODUCTS = MAX_COMPARE;

/**
 * Height of the sticky site header in PublicLayout: py-3 either side of an
 * h-12 logo, plus its bottom border. Only used at `lg`, where that header is
 * a single row and the number is stable.
 */
const HEADER_OFFSET = '73px';

interface Row {
    key: string;
    label: string;
    /** Every product gave the same answer — dimmed, and hidable. */
    same: boolean;
    value: (product: ComparedProduct) => ReactNode;
    /** Badge for the product that wins this row, where "wins" is meaningful. */
    winner?: (product: ComparedProduct) => string | null;
}

/**
 * Side-by-side product comparison.
 *
 * Two layouts rather than one that stretches, because the two sizes want
 * genuinely different things:
 *
 * - From `lg`, a real table. Four products plus a label column fit inside the
 *   page width, so nothing scrolls sideways and the product header can pin
 *   itself under the site header while you read down the rows.
 * - Below that, one card per attribute with the products listed inside it.
 *   Comparing means seeing the values *together*, and four table columns on a
 *   360px screen is about 70px each — technically side by side, and unreadable.
 *   Stacking per attribute keeps every value for a given row on screen at once
 *   with no horizontal scrolling at all, which is the thing that actually
 *   matters.
 *
 * An earlier version put the table in its own scroll box so it could pin both
 * axes. It worked, but it meant a scrolling region inside a scrolling page,
 * which on a phone is the worst of both: a short viewport, trapped scrolling,
 * and a label column eating a third of the width.
 *
 * The page is also explicit that it does not choose products. "Similar
 * products" and "recommended for you" are different features with different
 * data behind them; blurring them here is what makes a compare page feel like
 * it is guessing on your behalf.
 */
export default function Compare({ products, specRows = [] }: Props) {
    const { money } = useMoney();
    const { remove, clear } = useCompare();
    const [differencesOnly, setDifferencesOnly] = useState(false);

    if (products.length === 0) {
        return (
            <PublicLayout>
                <Head title="Compare products" />
                <Shell>
                    <EmptyState />
                </Shell>
            </PublicLayout>
        );
    }

    // ── Winners ──
    // Only where the direction is unarguable, and only when one product is
    // alone at the top: a badge on a tie is a lie about the tie.
    const prices = products.map((p) => p.priceKobo);
    const cheapest = Math.min(...prices);
    const cheapestIsUnique = prices.filter((p) => p === cheapest).length === 1;

    const ratings = products.map((p) => p.ratingAverage ?? 0);
    const bestRating = Math.max(...ratings);
    const bestRatingIsUnique = bestRating > 0 && ratings.filter((r) => r === bestRating).length === 1;

    const allSame = (read: (p: ComparedProduct) => unknown) =>
        products.length > 1 && new Set(products.map(read).map(String)).size === 1;

    const removeProduct = (uuid: string) => {
        // Drop it from the stored selection too — removing it from the address
        // alone would leave it in localStorage, so the tray would still count
        // it and the next comparison would quietly bring it back.
        remove(uuid);

        const rest = products.filter((p) => p.uuid !== uuid).map((p) => p.uuid);

        router.visit(
            rest.length > 0
                ? route('catalog.compare', { products: rest.join(',') })
                : route('catalog.index'),
        );
    };

    const clearAll = () => {
        clear();
        router.visit(route('catalog.index'));
    };

    // Annotated on the literal itself, then filtered separately: chaining
    // `.filter()` onto the literal makes the annotation apply to the call's
    // result instead, and the row callbacks lose their parameter types.
    const allSections: { title: string; rows: Row[] }[] = [
        {
            title: 'Overview',
            rows: [
                {
                    key: 'price',
                    label: 'Price',
                    same: allSame((p) => p.priceKobo),
                    value: (product) => (
                        <span className="inline-flex flex-wrap items-baseline gap-x-2">
                            <span className="text-base font-extrabold tabular-nums text-gray-900">
                                {money(product.priceKobo)}
                            </span>
                            {product.compareAtPriceKobo != null &&
                                product.compareAtPriceKobo > product.priceKobo && (
                                    <s className="text-xs tabular-nums text-gray-400">
                                        {money(product.compareAtPriceKobo)}
                                    </s>
                                )}
                        </span>
                    ),
                    winner: (product) =>
                        cheapestIsUnique && product.priceKobo === cheapest ? 'Lowest price' : null,
                },
                {
                    key: 'rating',
                    label: 'Rating',
                    same: allSame((p) => p.ratingAverage ?? 0),
                    value: (product) =>
                        product.ratingAverage ? (
                            <span className="inline-flex items-center gap-1.5">
                                <Star className="h-3.5 w-3.5 shrink-0 fill-amber-400 text-amber-400" />
                                <span className="font-bold tabular-nums text-gray-900">
                                    {product.ratingAverage.toFixed(1)}
                                </span>
                                <span className="text-xs text-gray-400">
                                    ({product.ratingCount ?? 0})
                                </span>
                            </span>
                        ) : (
                            <Blank>No ratings yet</Blank>
                        ),
                    winner: (product) =>
                        bestRatingIsUnique && product.ratingAverage === bestRating ? 'Best rated' : null,
                },
                {
                    key: 'availability',
                    label: 'Availability',
                    same: allSame((p) => (p.stockQuantity ?? 0) > 0),
                    value: (product) => {
                        const stock = product.stockQuantity ?? 0;

                        if (stock <= 0) {
                            return <span className="font-semibold text-red-600">Out of stock</span>;
                        }

                        return (
                            <span
                                className={`inline-flex items-center gap-1.5 font-semibold ${
                                    stock <= 5 ? 'text-amber-700' : 'text-emerald-700'
                                }`}
                            >
                                <Check className="h-3.5 w-3.5 shrink-0" />
                                {stock <= 5 ? `Only ${stock} left` : 'In stock'}
                            </span>
                        );
                    },
                },
                {
                    key: 'category',
                    label: 'Category',
                    same: allSame((p) => p.categoryName),
                    value: (product) => <span className="text-gray-700">{product.categoryName}</span>,
                },
                {
                    key: 'vendor',
                    label: 'Sold by',
                    same: allSame((p) => p.vendorName),
                    value: (product) => (
                        <span className="font-medium text-gray-700">{product.vendorName}</span>
                    ),
                },
            ],
        },
        {
            title: 'Specifications',
            /*
             * No winner on any of these, deliberately. A winner needs a
             * direction and the direction is not knowable from the field:
             * more RAM is better, less weight is better, a colour has no
             * better at all — and all three are the same attribute type.
             * Marking one anyway would be confidently wrong about half the
             * time. Doing it properly needs a winner rule (highest / lowest /
             * none) stored on the attribute definition.
             */
            rows: specRows.map((row) => ({
                key: `spec:${row.key}`,
                label: row.label,
                same: row.same,
                value: (product: ComparedProduct) =>
                    row.values[product.uuid] ?? <Blank>Not listed</Blank>,
            })),
        },
        {
            title: 'About',
            rows: [
                {
                    key: 'description',
                    label: 'Description',
                    same: allSame((p) => p.description?.trim() ?? ''),
                    value: (product: ComparedProduct) => (
                        <p className="line-clamp-6 text-sm leading-relaxed text-gray-600">
                            {product.description?.trim() || 'No description provided.'}
                        </p>
                    ),
                },
            ],
        },
    ];

    const sections = allSections.filter((section) => section.rows.length > 0);

    const identicalCount = sections.reduce(
        (total, section) => total + section.rows.filter((row) => row.same).length,
        0,
    );

    const visibleSections = sections
        .map((section) => ({
            ...section,
            rows: differencesOnly ? section.rows.filter((row) => !row.same) : section.rows,
        }))
        .filter((section) => section.rows.length > 0);

    const showAddColumn = products.length < MAX_PRODUCTS;
    const columns = products.length + (showAddColumn ? 1 : 0);
    const gridTemplateColumns = `200px repeat(${columns}, minmax(0, 1fr))`;

    return (
        <PublicLayout>
            <Head title="Compare products" />

            <Shell>
                {/* ── Header ── */}
                <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                            <GitCompareArrows className="h-5 w-5" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">
                                Compare products
                            </h1>
                            <p className="mt-1 max-w-prose text-sm leading-relaxed text-gray-500">
                                The products <strong className="font-semibold text-gray-700">you</strong>{' '}
                                picked, side by side. This page doesn't choose or suggest products —
                                add up to {MAX_PRODUCTS} with the compare control on any product card.
                            </p>
                        </div>
                    </div>

                    <Link
                        href={route('catalog.index')}
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:border-brand-300 hover:text-brand-700"
                    >
                        <ArrowLeft className="h-4 w-4" /> Keep shopping
                    </Link>
                </div>

                {/* ── Toolbar ── */}
                <div className="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-b border-gray-200 pb-4">
                    <span className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold tabular-nums text-slate-600">
                        {products.length} of {MAX_PRODUCTS} selected
                    </span>

                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                        {identicalCount > 0 && (
                            /* On a long spec list most rows match. Hiding them
                               is the shortest path to what separates these
                               products, which is the reason to be here. */
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-600">
                                <input
                                    type="checkbox"
                                    checked={differencesOnly}
                                    onChange={(event) => setDifferencesOnly(event.target.checked)}
                                    className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-2 focus:ring-brand-500/30"
                                />
                                Differences only
                                <span className="text-gray-400">({identicalCount} match)</span>
                            </label>
                        )}

                        <button
                            type="button"
                            onClick={clearAll}
                            className="rounded-full px-3 py-1.5 text-sm font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
                        >
                            Clear all
                        </button>
                    </div>
                </div>

                {products.length === 1 && (
                    <p className="mt-4 rounded-xl bg-brand-50 px-4 py-3 text-sm font-medium text-brand-800 ring-1 ring-inset ring-brand-100">
                        Add at least one more product to see them measured against each other.
                    </p>
                )}

                {/* ══ Small screens: one card per attribute ══ */}
                <div className="mt-5 space-y-6 lg:hidden">
                    {/* The legend. Numbers here are what the value rows below
                        refer back to, so a product is named once instead of
                        being repeated in full on every single row. */}
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {products.map((product, index) => (
                            <li
                                key={product.uuid}
                                className="relative rounded-2xl border border-gray-200 bg-white p-3 shadow-sm"
                            >
                                <button
                                    type="button"
                                    onClick={() => removeProduct(product.uuid)}
                                    aria-label={`Remove ${product.name} from the comparison`}
                                    className="absolute right-1.5 top-1.5 z-10 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-gray-400 shadow-sm transition hover:bg-red-50 hover:text-red-600"
                                >
                                    <X className="h-3 w-3" />
                                </button>

                                <Link href={route('catalog.product', product.slug)} className="block">
                                    <span className="flex h-24 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                        {product.imageUrl ? (
                                            <img
                                                loading="lazy"
                                                decoding="async"
                                                src={product.imageUrl}
                                                alt={product.name}
                                                className="h-full w-full object-contain"
                                            />
                                        ) : (
                                            <Package className="h-7 w-7 text-gray-300" />
                                        )}
                                    </span>

                                    <span className="mt-2 flex items-start gap-1.5">
                                        <Marker index={index} />
                                        <span className="line-clamp-2 text-xs font-bold leading-snug text-gray-900">
                                            {product.name}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}

                        {showAddColumn && (
                            <li>
                                <Link
                                    href={route('catalog.index')}
                                    className="flex h-full min-h-[9.5rem] flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-300 text-gray-400 transition hover:border-brand-400 hover:bg-brand-50/40 hover:text-brand-600"
                                >
                                    <Plus className="h-6 w-6" />
                                    <span className="text-xs font-bold">Add product</span>
                                </Link>
                            </li>
                        )}
                    </ul>

                    {visibleSections.map((section) => (
                        <section key={section.title}>
                            <h2 className="mb-2 px-1 text-xs font-extrabold uppercase tracking-wide text-gray-400">
                                {section.title}
                            </h2>

                            <div className="space-y-2.5">
                                {section.rows.map((row) => (
                                    <article
                                        key={row.key}
                                        className={`overflow-hidden rounded-2xl border bg-white shadow-sm ${
                                            row.same ? 'border-gray-100' : 'border-gray-200'
                                        }`}
                                    >
                                        <h3 className="flex items-center justify-between gap-2 border-b border-gray-100 bg-slate-50 px-3.5 py-2 text-xs font-bold text-gray-700">
                                            {row.label}
                                            {row.same && <SameChip />}
                                        </h3>

                                        <ul className="divide-y divide-gray-50">
                                            {products.map((product, index) => {
                                                const badge = row.winner?.(product) ?? null;

                                                return (
                                                    <li
                                                        key={product.uuid}
                                                        className={`flex items-start justify-between gap-3 px-3.5 py-2.5 ${
                                                            badge ? 'bg-emerald-50/50' : ''
                                                        }`}
                                                    >
                                                        <span className="flex min-w-0 shrink items-center gap-1.5">
                                                            <Marker index={index} />
                                                            <span className="truncate text-xs font-medium text-gray-500">
                                                                {product.name}
                                                            </span>
                                                        </span>

                                                        <span
                                                            className={`flex min-w-0 flex-col items-end gap-1 text-right text-sm ${
                                                                row.same ? 'text-gray-400' : ''
                                                            }`}
                                                        >
                                                            {row.value(product)}
                                                            {badge && <WinnerBadge label={badge} />}
                                                        </span>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </article>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>

                {/* ══ Large screens: a real table ══
                    Everything fits the page width, so nothing scrolls
                    sideways and the header can pin under the site header. */}
                <div className="mt-6 hidden lg:block">
                    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div
                            className="sticky z-20 grid border-b border-gray-200 bg-white"
                            style={{ gridTemplateColumns, top: HEADER_OFFSET }}
                        >
                            <div className="p-4" />

                            {products.map((product) => (
                                <div key={product.uuid} className="border-l border-gray-100 p-4">
                                    <div className="relative">
                                        <Link
                                            href={route('catalog.product', product.slug)}
                                            className="block"
                                        >
                                            <span className="flex h-32 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                                {product.imageUrl ? (
                                                    <img
                                                        loading="lazy"
                                                        decoding="async"
                                                        src={product.imageUrl}
                                                        alt={product.name}
                                                        className="h-full w-full object-contain"
                                                    />
                                                ) : (
                                                    <Package className="h-9 w-9 text-gray-300" />
                                                )}
                                            </span>
                                        </Link>

                                        <button
                                            type="button"
                                            onClick={() => removeProduct(product.uuid)}
                                            aria-label={`Remove ${product.name} from the comparison`}
                                            title="Remove from comparison"
                                            className="absolute -right-1.5 -top-1.5 flex h-7 w-7 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-400 shadow-sm transition hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                                        >
                                            <X className="h-3.5 w-3.5" />
                                        </button>
                                    </div>

                                    <Link
                                        href={route('catalog.product', product.slug)}
                                        className="mt-3 line-clamp-2 block text-sm font-bold leading-snug text-gray-900 hover:text-brand-600"
                                    >
                                        {product.name}
                                    </Link>

                                    <Link
                                        href={route('catalog.product', product.slug)}
                                        className="mt-3 block rounded-full bg-brand-600 py-2 text-center text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95"
                                    >
                                        View product
                                    </Link>
                                </div>
                            ))}

                            {showAddColumn && (
                                <div className="border-l border-gray-100 p-4">
                                    <Link
                                        href={route('catalog.index')}
                                        className="flex h-32 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 text-gray-400 transition hover:border-brand-400 hover:bg-brand-50/40 hover:text-brand-600"
                                    >
                                        <Plus className="h-6 w-6" />
                                        <span className="text-xs font-bold">Add a product</span>
                                    </Link>
                                </div>
                            )}
                        </div>

                        {visibleSections.map((section) => (
                            <div key={section.title}>
                                <div
                                    className="grid border-y border-gray-200 bg-slate-100"
                                    style={{ gridTemplateColumns }}
                                >
                                    <div className="px-4 py-2.5 text-xs font-extrabold uppercase tracking-wide text-gray-600">
                                        {section.title}
                                    </div>
                                    <div style={{ gridColumn: `span ${columns}` }} />
                                </div>

                                {section.rows.map((row) => (
                                    <div
                                        key={row.key}
                                        className="grid border-b border-gray-100 last:border-0 hover:bg-slate-50/60"
                                        style={{ gridTemplateColumns }}
                                    >
                                        <div className="flex items-start gap-2 bg-slate-50/70 px-4 py-3.5 text-sm font-semibold text-gray-600">
                                            {row.label}
                                            {row.same && <SameChip />}
                                        </div>

                                        {products.map((product) => {
                                            const badge = row.winner?.(product) ?? null;

                                            return (
                                                <div
                                                    key={product.uuid}
                                                    className={`flex flex-col items-start gap-1.5 border-l border-gray-100 px-4 py-3.5 text-sm ${
                                                        row.same ? 'text-gray-400' : ''
                                                    } ${badge ? 'bg-emerald-50/40' : ''}`}
                                                >
                                                    {row.value(product)}
                                                    {badge && <WinnerBadge label={badge} />}
                                                </div>
                                            );
                                        })}

                                        {showAddColumn && <div className="border-l border-gray-100" />}
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                </div>

                {visibleSections.length === 0 && (
                    <p className="mt-6 rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">
                        These products match on everything listed. Untick “Differences only” to see
                        the full comparison.
                    </p>
                )}
            </Shell>
        </PublicLayout>
    );
}

function Shell({ children }: { children: ReactNode }) {
    return <div className="mx-auto max-w-7xl px-3 py-6 sm:px-4 sm:py-8">{children}</div>;
}

/** Ties a value row back to the product legend without repeating the name. */
function Marker({ index }: { index: number }) {
    return (
        <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-slate-200 text-[10px] font-bold tabular-nums text-slate-600">
            {index + 1}
        </span>
    );
}

function SameChip() {
    return (
        <span
            title="Same for every product"
            className="shrink-0 rounded-full bg-gray-200 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-gray-500"
        >
            same
        </span>
    );
}

function WinnerBadge({ label }: { label: string }) {
    return (
        <span className="inline-flex w-fit items-center gap-1 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold text-white">
            <Check className="h-3 w-3 shrink-0" aria-hidden="true" />
            {label}
        </span>
    );
}

/** A value the vendor never supplied — stated, not left ambiguously blank. */
function Blank({ children }: { children: ReactNode }) {
    return <span className="text-gray-300">{children}</span>;
}

function EmptyState() {
    return (
        <div className="mx-auto max-w-md rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center">
            <GitCompareArrows className="mx-auto h-10 w-10 text-gray-300" />
            <h1 className="mt-4 text-lg font-extrabold text-gray-900">Nothing to compare yet</h1>
            <p className="mt-1.5 text-sm leading-relaxed text-gray-500">
                Use the compare control on any product card to add it here. Pick two or more and
                this page lays them out side by side.
            </p>
            <Link
                href={route('catalog.index')}
                className="mt-5 inline-block rounded-full bg-brand-600 px-6 py-3 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
            >
                Browse products
            </Link>
        </div>
    );
}
