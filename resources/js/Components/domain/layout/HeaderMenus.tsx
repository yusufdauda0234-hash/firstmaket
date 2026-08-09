import { AVATAR_COLOR_CYCLE } from '@/Components/domain/search/SearchBox';
import PopoverCaret from '@/Components/ui/PopoverCaret';
import SelectMenu from '@/Components/ui/SelectMenu';
import type { CountryEntry } from '@/Data/countries';
import { useHoverPopover, usePopover } from '@/Hooks/use-popover';
import { useI18n, useMoney, useTranslation } from '@/Hooks/useI18n';
import { Category, PageProps } from '@/Types';
import { productLinkProps } from '@/Utils/links';
import { Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, ChevronLeft, ChevronRight, Flame, LayoutGrid, Search, Smartphone, Sparkles, Tag } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const categoryEmoji: Record<string, string> = {
    electronics: '📺',
    'home-appliances': '🧊',
    'solar-equipment': '🔆',
    furniture: '🛋️',
    fashion: '👗',
    'business-equipment': '🖨️',
};

interface CategoryTile {
    label: string;
    emoji?: string;
    hot?: boolean;
    href: string;
}

/** Lightweight product shape returned by the catalog.menu-products endpoint. */
interface MenuProduct {
    name: string;
    slug: string;
    priceKobo: number;
    compareAtPriceKobo: number | null;
    imageUrl: string | null;
}

interface CategoriesMenuProps {
    categories: Category[];
    /** When true, closes this dropdown (e.g. the search bar just opened). */
    forceClose?: boolean;
    /** Called the moment this component opens its own dropdown. */
    onOpen?: () => void;
}

/**
 * "Categories" hover-or-click mega menu (Temu interaction, FirstMaket
 * styling): left sidebar of category groups, right grid of quick-link tiles.
 */
export function CategoriesMenu({ categories, forceClose = false, onOpen }: CategoriesMenuProps) {
    const { t } = useTranslation();
    const { open, setOpen, ref, openNow, hoverProps } = useHoverPopover<HTMLDivElement>();
    const [activeGroupId, setActiveGroupId] = useState('all');
    // Products per group, fetched lazily the first time a group is viewed.
    const [menuProducts, setMenuProducts] = useState<Record<string, MenuProduct[]>>({});

    useEffect(() => {
        if (!open || menuProducts[activeGroupId]) return;
        const controller = new AbortController();
        fetch(
            route('catalog.menu-products', activeGroupId === 'all' ? {} : { category: activeGroupId }),
            { headers: { Accept: 'application/json' }, signal: controller.signal },
        )
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('failed'))))
            .then((body: { products: MenuProduct[] }) =>
                setMenuProducts((current) => ({ ...current, [activeGroupId]: body.products })),
            )
            .catch(() => {
                // best effort — the menu still works without the product strip
            });
        return () => controller.abort();
    }, [open, activeGroupId, menuProducts]);

    const activeProducts = menuProducts[activeGroupId];

    /*
     * Sidebar = "All Categories" + each real category. The "all" panel shows
     * one tile per top-level category; a category's panel shows its real
     * sub-categories alongside its products.
     *
     * Those tiles used to be hardcoded empty, back when sub-categories did not
     * exist and anything shown there would have been invented. They do exist
     * now, and without them hovering a parent rendered a blank panel.
     */
    const tilesFor = (
        items: { name: string; slug: string }[],
    ): (CategoryTile & { color: string })[] =>
        items.map((item, i) => ({
            label: item.name,
            emoji: categoryEmoji[item.slug] ?? '🛍️',
            href: route('catalog.index', { category: item.slug }),
            color: AVATAR_COLOR_CYCLE[i % AVATAR_COLOR_CYCLE.length],
        }));

    const groups = useMemo(() => {
        const all = {
            id: 'all',
            label: 'All Categories',
            tiles: tilesFor(categories),
        };
        const perCategory = categories.map((category) => ({
            id: category.slug,
            label: category.name,
            tiles: tilesFor(category.children ?? []),
        }));
        return [all, ...perCategory];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [categories]);

    const activeGroup = groups.find((g) => g.id === activeGroupId) ?? groups[0];
    const { money } = useMoney();

    // Another header module (e.g. the search bar) opened — close this one.
    useEffect(() => {
        if (forceClose) setOpen(false);
    }, [forceClose, setOpen]);

    const openSelf = () => {
        openNow();
        onOpen?.();
    };

    return (
        <div
            ref={ref}
            className="relative shrink-0"
            onMouseEnter={() => openSelf()}
            onMouseLeave={hoverProps.onMouseLeave}
        >
            <button
                type="button"
                onClick={() => (open ? setOpen(false) : openSelf())}
                aria-haspopup="true"
                aria-expanded={open}
                className={`flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3.5 py-2.5 text-sm font-medium shadow-sm transition-colors ${
                    open
                        ? 'border-brand-600 bg-brand-50 text-brand-700'
                        : 'border-gray-200 bg-white text-gray-700 hover:bg-slate-50'
                }`}
            >
                <LayoutGrid className="h-4 w-4" />
                <span className="hidden sm:inline">{t('Categories')}</span>
                <ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute left-0 z-50 mt-2 w-[calc(100vw-2rem)] max-w-[1100px] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg shadow-slate-900/10 sm:w-[70vw]">
                    <div className="flex max-h-[75vh] min-h-[65vh] flex-col md:flex-row">
                        {/* Sidebar */}
                        <div className="w-full shrink-0 overflow-x-auto border-b border-slate-100 bg-slate-50 py-2 md:min-h-[65vh] md:max-h-[75vh] md:w-60 md:overflow-x-hidden md:overflow-y-auto md:border-b-0 md:border-r">
                            {groups.map((group) => {
                                const active = group.id === activeGroupId;
                                return (
                                    <button
                                        key={group.id}
                                        type="button"
                                        onMouseEnter={() => setActiveGroupId(group.id)}
                                        onClick={() => setActiveGroupId(group.id)}
                                        className={`flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left text-sm transition-colors ${
                                            active
                                                ? 'bg-white font-medium text-slate-900'
                                                : 'text-gray-600 hover:bg-white/70'
                                        }`}
                                    >
                                        <span className="truncate">{group.label}</span>
                                        <ChevronRight className="h-3.5 w-3.5 shrink-0 text-gray-400" />
                                    </button>
                                );
                            })}
                        </div>

                        {/* Tile grid (All Categories only) + product strip */}
                        <div className="min-h-[65vh] max-h-[75vh] flex-1 overflow-y-auto p-5">
                            {activeGroup.tiles.length > 0 && (
                            <div className="grid grid-cols-4 gap-x-2 gap-y-4 sm:grid-cols-5">
                                {activeGroup.tiles.map((tile) => (
                                    <Link
                                        key={tile.label}
                                        href={tile.href}
                                        onClick={() => setOpen(false)}
                                        className="flex flex-col items-center gap-2 rounded-lg p-2 text-center transition-colors hover:bg-slate-50"
                                    >
                                        <span className="relative">
                                            <span
                                                className={`flex h-14 w-14 shrink-0 items-center justify-center rounded-full text-2xl ${tile.color}`}
                                            >
                                                {tile.emoji}
                                            </span>
                                            {tile.hot && (
                                                <span className="absolute -right-2 -top-1 rounded-full bg-orange-500 px-1.5 py-0.5 text-[9px] font-bold leading-none text-white shadow">
                                                    HOT
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-xs leading-snug text-gray-700">{tile.label}</span>
                                    </Link>
                                ))}
                            </div>
                            )}

                            {/* Real merchandise for the active group */}
                            <div className={activeGroup.tiles.length > 0 ? 'mt-5 border-t border-slate-100 pt-4' : ''}>
                                <div className="mb-3 flex items-center justify-between">
                                    <h3 className="text-sm font-bold text-gray-900">
                                        {activeGroupId === 'all'
                                            ? 'Trending now'
                                            : `Popular in ${activeGroup.label}`}
                                    </h3>
                                    <Link
                                        href={
                                            activeGroupId === 'all'
                                                ? route('catalog.index')
                                                : route('catalog.index', { category: activeGroupId })
                                        }
                                        onClick={() => setOpen(false)}
                                        className="text-xs font-semibold text-brand-600 hover:underline"
                                    >
                                        See all →
                                    </Link>
                                </div>

                                {activeProducts === undefined ? (
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        {Array.from({ length: 4 }).map((_, i) => (
                                            <div key={i} className="animate-pulse">
                                                <div className="aspect-square rounded-xl bg-slate-100" />
                                                <div className="mt-2 h-3 w-3/4 rounded bg-slate-100" />
                                                <div className="mt-1.5 h-3 w-1/2 rounded bg-slate-100" />
                                            </div>
                                        ))}
                                    </div>
                                ) : activeProducts.length === 0 ? (
                                    <p className="rounded-lg bg-slate-50 px-3 py-4 text-center text-xs text-gray-400">
                                        No products in this category yet — check back soon.
                                    </p>
                                ) : (
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        {activeProducts.map((product) => (
                                            <a
                                                key={product.slug}
                                                {...productLinkProps(product.slug)}
                                                onClick={() => setOpen(false)}
                                                className="group/menuitem min-w-0"
                                            >
                                                <span className="flex aspect-square items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                                    {product.imageUrl ? (
                                                        <img
                                                            src={product.imageUrl}
                                                            alt={product.name}
                                                            loading="lazy"
                                                            className="h-full w-full object-cover transition-transform group-hover/menuitem:scale-105"
                                                        />
                                                    ) : (
                                                        <img
                                                            src="/images/brand/logo-mark-blue.png"
                                                            alt=""
                                                            className="h-8 w-8 opacity-30"
                                                        />
                                                    )}
                                                </span>
                                                <span className="mt-1.5 block truncate text-xs text-gray-700 group-hover/menuitem:text-brand-600">
                                                    {product.name}
                                                </span>
                                                <span className="flex items-baseline gap-1.5">
                                                    <span className="text-sm font-bold text-brand-700">
                                                        {money(product.priceKobo)}
                                                    </span>
                                                    {product.compareAtPriceKobo != null &&
                                                        product.compareAtPriceKobo > product.priceKobo && (
                                                            <s className="text-[10px] text-gray-400">
                                                                {money(product.compareAtPriceKobo)}
                                                            </s>
                                                        )}
                                                </span>
                                            </a>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Footer quick links (absorbs the old category nav strip) */}
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-sm">
                        <Link
                            href={route('catalog.index', { sort: 'newest' })}
                            onClick={() => setOpen(false)}
                            className="flex items-center gap-1.5 font-semibold text-brand-700 hover:underline"
                        >
                            <Flame className="h-4 w-4 text-orange-500" /> New In
                        </Link>
                        <Link
                            href={route('catalog.index')}
                            onClick={() => setOpen(false)}
                            className="flex items-center gap-1.5 text-gray-600 hover:text-brand-700"
                        >
                            <Tag className="h-4 w-4 text-gray-400" /> Today's Deals
                        </Link>
                        <Link
                            href={route('vendor.register')}
                            onClick={() => setOpen(false)}
                            className="flex items-center gap-1.5 text-gray-600 hover:text-brand-700"
                        >
                            <Sparkles className="h-4 w-4 text-gray-400" /> {t('Sell on FirstMaket')}
                        </Link>
                        <Link
                            href={route('catalog.index')}
                            onClick={() => setOpen(false)}
                            className="ml-auto text-gray-500 hover:text-brand-700"
                        >
                            Browse everything →
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Locale (ship to / language / currency)
// ---------------------------------------------------------------------------

function flagUrl(countryCode: string, width: 20 | 40 = 40) {
    // flagcdn serves the same flag set restcountries links to.
    return `https://flagcdn.com/w${width}/${countryCode.toLowerCase()}.png`;
}

/**
 * Flag image that yields to the country code if the CDN is unreachable, so an
 * offline header degrades to text instead of a row of broken-image icons.
 */
function Flag({ code, className = '' }: { code: string; className?: string }) {
    const [failed, setFailed] = useState(false);

    if (failed) {
        return (
            <span
                aria-hidden="true"
                className={`flex shrink-0 items-center justify-center rounded-[2px] bg-slate-100 text-[8px] font-bold text-gray-500 ${className}`}
            >
                {code}
            </span>
        );
    }

    return (
        <img
            src={flagUrl(code, 20)}
            srcSet={`${flagUrl(code, 40)} 2x`}
            alt=""
            loading="lazy"
            onError={() => setFailed(true)}
            className={`shrink-0 rounded-[2px] object-cover shadow-sm ${className}`}
        />
    );
}

function CountryRow({
    country,
    selected,
    onChoose,
}: {
    country: CountryEntry;
    selected: boolean;
    onChoose: (country: CountryEntry) => void;
}) {
    return (
        <li>
            <button
                type="button"
                onClick={() => onChoose(country)}
                className={`flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left text-sm transition-colors hover:bg-brand-50 ${
                    selected ? 'bg-brand-50 font-medium text-brand-700' : 'text-gray-700'
                }`}
            >
                <Flag code={country.code} className="h-3.5 w-5" />
                <span className="truncate">{country.name}</span>
                <span className="ml-auto shrink-0 pl-2 text-xs text-gray-400">{country.currency}</span>
                {selected && <Check className="ml-1 h-4 w-4 shrink-0" />}
            </button>
        </li>
    );
}

/**
 * Ship-to / language / currency popover (Temu interaction): hover to open,
 * custom dropdowns, and a "Change country/region" view.
 *
 * The choice is server state, not a browser preference: picking a language
 * posts it and the next render comes back translated, and picking a currency
 * changes the rate every price on the page is formatted with. Only languages
 * we have strings for and currencies staff maintain a rate for are offered —
 * an option that changed the label and nothing else is what this replaced.
 */
export function LocalePopover() {
    const { open, ref, hoverProps } = useHoverPopover<HTMLDivElement>();
    const { t } = useTranslation();
    const { locale, locales, currency, currencies, country: countryCode } = useI18n();
    const [countries, setCountries] = useState<CountryEntry[]>([]);
    const [view, setView] = useState<'main' | 'country'>('main');
    const [countrySearch, setCountrySearch] = useState('');

    // Reset to the main view whenever the popover closes.
    useEffect(() => {
        if (!open) {
            setView('main');
            setCountrySearch('');
        }
    }, [open]);

    // Pulled from our own bundle the first time the popover opens. Split into
    // its own chunk so the ~250-country table never weighs on first paint, and
    // cached by the browser from then on. No third party involved, so there is
    // no failure state to render.
    useEffect(() => {
        if (!open || countries.length > 0) return;
        let cancelled = false;
        import('@/Data/countries').then((module) => {
            if (!cancelled) setCountries(module.COUNTRIES);
        });
        return () => {
            cancelled = true;
        };
    }, [open, countries.length]);

    const selectedCountry = countries.find((c) => c.code === countryCode);

    const filteredCountries = useMemo(() => {
        const term = countrySearch.trim().toLowerCase();
        if (!term) return countries;
        return countries.filter(
            (c) => c.name.toLowerCase().includes(term) || c.currency.toLowerCase().includes(term),
        );
    }, [countries, countrySearch]);

    const africanCountries = filteredCountries.filter((c) => c.region === 'Africa');
    const otherCountries = filteredCountries.filter((c) => c.region !== 'Africa');

    /**
     * Applied immediately on click (Temu behaviour). preserveScroll keeps the
     * shopper where they were — switching currency halfway down a product page
     * should not throw them back to the top.
     */
    function apply(next: { locale?: string; currency?: string; country?: string }) {
        router.post(route('locale.update'), next, {
            preserveScroll: true,
            preserveState: false,
        });
    }

    /**
     * Choosing a country also moves the currency, but only if we actually
     * maintain a rate for it — otherwise prices stay in naira rather than
     * quoting a number we cannot stand behind.
     */
    function chooseCountry(country: CountryEntry) {
        const supported = currencies.some((c) => c.code === country.currency);

        apply({
            country: country.code,
            currency: supported ? country.currency : undefined,
        });
        setView('main');
        setCountrySearch('');
    }

    return (
        <div ref={ref} className="relative hidden lg:block" {...hoverProps}>
            <button
                type="button"
                aria-expanded={open}
                className="flex items-center gap-1.5 text-sm text-gray-600 hover:text-brand-600"
            >
                <Flag code={countryCode} className="h-3.5 w-5" />
                <span className="text-left">
                    <span className="block text-[11px] leading-tight text-gray-400">
                        {locales.find((l) => l.code === locale)?.badge ?? locale.toUpperCase()}
                    </span>
                    <span className="block font-semibold leading-tight text-gray-800">
                        {currency.symbol} {currency.code}
                    </span>
                </span>
                <ChevronDown className={`h-3.5 w-3.5 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute right-0 top-full z-50 mt-3 w-80 min-w-[320px] rounded-2xl border border-gray-200 bg-white p-5 shadow-xl shadow-slate-900/10">
                    <PopoverCaret className="right-9" />

                    {view === 'main' ? (
                        <>
                            {/* Language — only what we have strings for */}
                            <h3 className="px-1 text-sm font-semibold text-slate-900">{t('Language')}</h3>
                            <SelectMenu
                                className="mt-2"
                                ariaLabel={t('Language')}
                                value={locale}
                                options={locales.map((l) => ({
                                    value: l.code,
                                    // Endonym first, so a speaker recognises it
                                    // without reading English.
                                    label: l.endonym === l.english ? l.endonym : `${l.endonym} · ${l.english}`,
                                }))}
                                onChange={(code) => apply({ locale: code })}
                            />

                            <div className="my-3 border-t border-gray-100" />

                            {/* Currency — only codes staff maintain a rate for */}
                            <h3 className="px-1 text-sm font-semibold text-slate-900">{t('Currency')}</h3>
                            <SelectMenu
                                className="mt-2"
                                ariaLabel={t('Currency')}
                                value={currency.code}
                                options={currencies.map((c) => ({
                                    value: c.code,
                                    label: `${c.code}: ${c.symbol}`,
                                }))}
                                onChange={(code) => apply({ currency: code })}
                            />

                            <div className="my-3 border-t border-gray-100" />

                            <p className="px-1 text-center text-sm leading-snug text-gray-500">
                                {selectedCountry
                                    ? `${t('Ship to')}: ${selectedCountry.name}`
                                    : `${t('Ship to')}: ${countryCode}`}
                            </p>
                            <button
                                type="button"
                                onClick={() => setView('country')}
                                className="mt-3 w-full rounded-full border border-gray-300 py-2 text-sm font-medium text-gray-800 transition-colors hover:border-gray-400 hover:bg-slate-50"
                            >
                                {t('Change country/region')}
                            </button>
                            {!currency.isBase && (
                                <p className="mt-3 px-1 text-xs leading-snug text-gray-400">
                                    {t('Prices are indicative — you pay in Nigerian Naira.')}
                                </p>
                            )}
                        </>
                    ) : (
                        <>
                            {/* Country/region picker view */}
                            <div className="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => setView('main')}
                                    aria-label="Back"
                                    className="rounded-full p-1 text-gray-500 transition-colors hover:bg-slate-100 hover:text-gray-700"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </button>
                                <h3 className="text-sm font-semibold text-slate-900">{t('Change country/region')}</h3>
                            </div>

                            <div className="mt-2 flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2">
                                <Search className="h-3.5 w-3.5 shrink-0 text-gray-400" />
                                <input
                                    value={countrySearch}
                                    onChange={(e) => setCountrySearch(e.target.value)}
                                    placeholder="Search countries"
                                    className="w-full bg-transparent text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none"
                                    autoFocus
                                />
                            </div>

                            <ul className="mt-2 max-h-64 overflow-y-auto">
                                {countries.length > 0 && filteredCountries.length === 0 && (
                                    <li className="px-3 py-4 text-center text-xs text-gray-400">
                                        No country matches “{countrySearch}”.
                                    </li>
                                )}
                                {africanCountries.length > 0 && (
                                    <li className="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                        Africa
                                    </li>
                                )}
                                {africanCountries.map((country) => (
                                    <CountryRow
                                        key={country.code}
                                        country={country}
                                        selected={country.code === countryCode}
                                        onChoose={chooseCountry}
                                    />
                                ))}
                                {otherCountries.length > 0 && (
                                    <li className="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                        Rest of the world
                                    </li>
                                )}
                                {otherCountries.map((country) => (
                                    <CountryRow
                                        key={country.code}
                                        country={country}
                                        selected={country.code === countryCode}
                                        onChoose={chooseCountry}
                                    />
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// GetAppPopover
// ---------------------------------------------------------------------------

/** 21×21 QR code matrix — correct finder patterns & timing stripes. */
const QR_MATRIX: number[][] = [
    [1,1,1,1,1,1,1,0,1,0,1,0,0,0,1,1,1,1,1,1,1],
    [1,0,0,0,0,0,1,0,1,1,0,1,0,0,1,0,0,0,0,0,1],
    [1,0,1,1,1,0,1,0,0,0,1,0,1,0,1,0,1,1,1,0,1],
    [1,0,1,1,1,0,1,0,1,0,0,1,0,0,1,0,1,1,1,0,1],
    [1,0,1,1,1,0,1,0,0,1,1,0,1,0,1,0,1,1,1,0,1],
    [1,0,0,0,0,0,1,0,1,0,0,0,1,0,1,0,0,0,0,0,1],
    [1,1,1,1,1,1,1,0,1,0,1,0,1,0,1,1,1,1,1,1,1],
    [0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,1,0],
    [1,0,1,0,1,0,1,1,0,0,1,0,1,0,1,0,1,0,1,0,1],
    [0,1,0,1,0,1,0,0,1,0,0,1,0,1,0,1,0,1,0,1,0],
    [1,0,0,1,1,0,1,0,1,0,1,0,1,0,0,1,1,0,0,1,0],
    [0,1,0,0,0,1,0,1,0,1,0,0,0,1,0,0,0,1,0,0,1],
    [1,1,0,1,0,0,1,0,0,0,1,0,1,0,1,0,0,1,1,0,0],
    [0,0,0,0,0,0,0,0,1,1,0,1,0,0,0,0,0,1,0,0,1],
    [1,1,1,1,1,1,1,0,1,0,0,1,0,0,0,1,0,0,1,0,1],
    [1,0,0,0,0,0,1,0,0,1,1,0,0,0,1,0,1,0,0,1,0],
    [1,0,1,1,1,0,1,0,1,0,0,1,0,0,0,1,0,0,1,0,0],
    [1,0,1,1,1,0,1,0,0,0,1,0,0,0,1,0,0,0,0,0,1],
    [1,0,1,1,1,0,1,0,1,0,0,1,0,0,0,0,1,0,0,1,0],
    [1,0,0,0,0,0,1,0,0,0,1,0,0,1,0,1,0,0,0,0,1],
    [1,1,1,1,1,1,1,0,0,1,0,0,1,0,1,0,0,1,0,0,0],
];

function QRCodeDisplay() {
    const M = 6; // pixels per module
    const Q = 2; // quiet-zone modules
    const T = (21 + Q * 2) * M;
    return (
        <svg width={T} height={T} viewBox={`0 0 ${T} ${T}`} aria-hidden="true">
            <rect width={T} height={T} fill="white" />
            {QR_MATRIX.map((row, r) =>
                row.map((cell, c) =>
                    cell ? (
                        <rect
                            key={`${r}-${c}`}
                            x={(c + Q) * M}
                            y={(r + Q) * M}
                            width={M}
                            height={M}
                            fill="#111827"
                        />
                    ) : null,
                ),
            )}
        </svg>
    );
}

/**
 * "Get the app" popover — authentic QR code at the top, store badges in a
 * flex row below it. The trigger button stands out on the utility bar.
 */
export function GetAppPopover() {
    const [open, setOpen] = useState(false);
    const wrapperRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        function handleOutsideClick(e: MouseEvent) {
            if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', handleOutsideClick);
        return () => document.removeEventListener('mousedown', handleOutsideClick);
    }, []);

    return (
        <div
            ref={wrapperRef}
            className="relative"
        >
            {/* ── Trigger button — stands out from plain utility-bar links ── */}
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                className="flex items-center gap-1.5 rounded-full bg-brand-yellow px-3 py-1.5 text-[11px] font-bold tracking-wide text-brand-900 shadow-sm transition hover:brightness-110 active:scale-95"
            >
                <Smartphone className="h-3.5 w-3.5" />
                Get the app
            </button>

            {open && (
                <div className="fixed inset-x-2 top-16 z-50 max-h-[calc(100vh-5rem)] overflow-y-auto rounded-2xl border border-gray-100 bg-white p-5 text-center shadow-2xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[380px] sm:max-w-[94vw] sm:p-6">
                    {/* Title */}
                    <p className="mb-3 text-sm font-bold text-gray-900">Get the FirstMaket app</p>

                    {/* QR code — real pixel grid with brand logo centred inside */}
                    <div className="relative mx-auto inline-block overflow-hidden rounded-xl ring-1 ring-gray-200">
                        <QRCodeDisplay />
                        <div className="absolute inset-0 flex items-center justify-center">
                            <div className="rounded-lg bg-white p-1.5 shadow-md ring-1 ring-gray-100">
                                <img
                                    src="/images/brand/logo-mark-blue.png"
                                    alt="FirstMaket"
                                    className="h-8 w-8 rounded-md object-contain"
                                />
                            </div>
                        </div>
                    </div>

                    <p className="mt-2 text-[10px] text-gray-400">Scan with your phone camera</p>

                    {/* Store badges — flex row below the QR code */}
                    <div className="mt-3.5 flex gap-2">
                        {/* Google Play */}
                        <div className="flex flex-1 cursor-default items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2.5 opacity-70">
                            <img
                                src="/images/brand/playstore.png"
                                alt="Google Play"
                                className="h-6 w-6 shrink-0 object-contain"
                            />
                            <div className="text-left">
                                <p className="text-[9px] leading-none text-gray-500">Get it on</p>
                                <p className="mt-0.5 text-xs font-bold leading-tight text-gray-900">Google Play</p>
                            </div>
                        </div>

                        {/* App Store */}
                        <div className="flex flex-1 cursor-default items-center gap-2 rounded-xl border border-gray-200 bg-black px-3 py-2.5 opacity-70">
                            <img
                                src="/images/brand/apple-logo.png"
                                alt="App Store"
                                className="h-6 w-6 shrink-0 object-contain brightness-0 invert"
                            />
                            <div className="text-left">
                                <p className="text-[9px] leading-none text-gray-400">Download on the</p>
                                <p className="mt-0.5 text-xs font-bold leading-tight text-white">App Store</p>
                            </div>
                        </div>
                    </div>

                    <p className="mt-1.5 text-[10px] font-medium text-gray-400">Coming soon</p>

                    <p className="mt-3 border-t border-gray-100 pt-3 text-[11px] leading-snug text-gray-400">
                        Until the apps ship, FirstMaket works great in your phone's browser.
                    </p>
                </div>
            )}
        </div>
    );
}

/** Help/support dropdown (Temu interaction). */
export function HelpMenu({ hotline }: { hotline: string }) {
    const { open, setOpen, ref } = usePopover<HTMLDivElement>();
    const { auth } = usePage<PageProps>().props;

    return (
            <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                aria-haspopup="menu"
                className="hover:text-white"
            >
                Help
            </button>

            {open && (
                <div
                    role="menu"
                    className="fixed left-2 right-2 top-10 z-50 max-h-[calc(100dvh-3.5rem)] overflow-y-auto rounded-2xl border border-gray-100 bg-white p-2 text-left shadow-xl sm:absolute sm:left-auto sm:right-0 sm:top-full sm:mt-2 sm:max-h-none sm:w-64"
                >
                    <a
                        href={`tel:${hotline.replace(/[^+\d]/g, '')}`}
                        className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-gray-700 hover:bg-brand-50 hover:text-brand-700"
                    >
                        📞 Call to order — {hotline}
                    </a>
                    <Link
                        href={route('faq')}
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-gray-700 hover:bg-brand-50 hover:text-brand-700"
                    >
                        ❓ FAQ and contact
                    </Link>
                    <Link
                        href={route('vendor.register')}
                        className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-gray-700 hover:bg-brand-50 hover:text-brand-700"
                    >
                        🏪 Become a vendor
                    </Link>
                    <Link
                        href={auth.user ? route('orders.index') : route('login')}
                        className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-gray-700 hover:bg-brand-50 hover:text-brand-700"
                    >
                        📦 Track my order
                    </Link>
                    {auth.user && (
                        <Link
                            href={route('support.index')}
                            className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-gray-700 hover:bg-brand-50 hover:text-brand-700"
                        >
                            💬 Support Center
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}
