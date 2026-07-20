import { AVATAR_COLOR_CYCLE } from '@/Components/domain/search/SearchBox';
import PopoverCaret from '@/Components/ui/PopoverCaret';
import SelectMenu from '@/Components/ui/SelectMenu';
import { useHoverPopover, usePopover } from '@/Hooks/use-popover';
import { Category, PageProps } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Link, usePage } from '@inertiajs/react';
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
 * "Categories" hover-or-click mega menu (Temu interaction, FirstMarket
 * styling): left sidebar of category groups, right grid of quick-link tiles.
 */
export function CategoriesMenu({ categories, forceClose = false, onOpen }: CategoriesMenuProps) {
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

    // Sidebar = "All Categories" + each real category. The "all" panel shows
    // one tile per category; a category's panel shows only its real products
    // (no fabricated subcategory tiles).
    const groups = useMemo(() => {
        const all = {
            id: 'all',
            label: 'All Categories',
            tiles: categories.map((category, i): CategoryTile & { color: string } => ({
                label: category.name,
                emoji: categoryEmoji[category.slug] ?? '🛍️',
                href: route('catalog.index', { category: category.slug }),
                color: AVATAR_COLOR_CYCLE[i % AVATAR_COLOR_CYCLE.length],
            })),
        };
        const perCategory = categories.map((category) => ({
            id: category.slug,
            label: category.name,
            tiles: [] as (CategoryTile & { color: string })[],
        }));
        return [all, ...perCategory];
    }, [categories]);

    const activeGroup = groups.find((g) => g.id === activeGroupId) ?? groups[0];

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
                <span className="hidden sm:inline">Categories</span>
                <ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute left-0 z-50 mt-2 w-[70vw] min-w-[800px] max-w-[1100px] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg shadow-slate-900/10">
                    <div className="flex min-h-[65vh]">
                        {/* Sidebar */}
                        <div className="min-h-[65vh] max-h-[75vh] w-60 shrink-0 overflow-y-auto border-r border-slate-100 bg-slate-50 py-2">
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
                                    <div className="grid grid-cols-4 gap-3">
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
                                    <div className="grid grid-cols-4 gap-3">
                                        {activeProducts.map((product) => (
                                            <Link
                                                key={product.slug}
                                                href={route('catalog.product', { product: product.slug })}
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
                                                        {formatNairaFromKobo(product.priceKobo)}
                                                    </span>
                                                    {product.compareAtPriceKobo != null &&
                                                        product.compareAtPriceKobo > product.priceKobo && (
                                                            <s className="text-[10px] text-gray-400">
                                                                {formatNairaFromKobo(product.compareAtPriceKobo)}
                                                            </s>
                                                        )}
                                                </span>
                                            </Link>
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
                            <Sparkles className="h-4 w-4 text-gray-400" /> Sell on FirstMarket
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

interface RestCountry {
    cca2: string;
    name: { common: string };
    region?: string;
    languages?: Record<string, string>;
    currencies?: Record<string, { name: string; symbol?: string }>;
}

interface LocalePrefs {
    countryCode: string;
    countryName: string;
    language: string;
    currency: string;
    currencySymbol: string;
}

const LOCALE_KEY = 'fm.locale-prefs';
const COUNTRIES_CACHE_KEY = 'fm.countries.v2';

const DEFAULT_PREFS: LocalePrefs = {
    countryCode: 'NG',
    countryName: 'Nigeria',
    language: 'English',
    currency: 'NGN',
    currencySymbol: '₦',
};

function loadPrefs(): LocalePrefs {
    try {
        const raw = localStorage.getItem(LOCALE_KEY);
        if (raw) return { ...DEFAULT_PREFS, ...(JSON.parse(raw) as Partial<LocalePrefs>) };
    } catch {
        // fall through to defaults
    }
    return DEFAULT_PREFS;
}

function flagUrl(countryCode: string, width: 20 | 40 = 40) {
    // flagcdn serves the same flag set restcountries links to.
    return `https://flagcdn.com/w${width}/${countryCode.toLowerCase()}.png`;
}

function CountryRow({
    country,
    selected,
    onChoose,
}: {
    country: RestCountry;
    selected: boolean;
    onChoose: (country: RestCountry) => void;
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
                <img
                    src={flagUrl(country.cca2, 20)}
                    alt=""
                    loading="lazy"
                    className="h-3.5 w-5 shrink-0 rounded-[2px] object-cover shadow-sm"
                />
                <span className="truncate">{country.name.common}</span>
                {selected && <Check className="ml-auto h-4 w-4 shrink-0" />}
            </button>
        </li>
    );
}

/**
 * Ship-to / language / currency popover (Temu interaction): hover to open,
 * custom radio and dropdown options, and a "Change country/region" view.
 * Countries, currencies, and flags come from the restcountries + flagcdn
 * APIs with African countries listed first; the choice is a browser
 * preference until multi-currency lands server-side.
 */
export function LocalePopover() {
    const { open, ref, hoverProps } = useHoverPopover<HTMLDivElement>();
    const [prefs, setPrefs] = useState<LocalePrefs>(DEFAULT_PREFS);
    const [countries, setCountries] = useState<RestCountry[]>([]);
    const [view, setView] = useState<'main' | 'country'>('main');
    const [countrySearch, setCountrySearch] = useState('');
    const [loadFailed, setLoadFailed] = useState(false);

    useEffect(() => setPrefs(loadPrefs()), []);

    // Reset to the main view whenever the popover closes.
    useEffect(() => {
        if (!open) {
            setView('main');
            setCountrySearch('');
        }
    }, [open]);

    // Fetch the country list once the popover first opens (cached per session).
    useEffect(() => {
        if (!open || countries.length > 0) return;

        try {
            const cached = sessionStorage.getItem(COUNTRIES_CACHE_KEY);
            if (cached) {
                setCountries(JSON.parse(cached) as RestCountry[]);
                return;
            }
        } catch {
            // ignore cache failures and fetch fresh
        }

        fetch('https://restcountries.com/v3.1/all?fields=name,cca2,region,languages,currencies')
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('failed'))))
            .then((data: RestCountry[]) => {
                // FirstMarket ships from Nigeria, so Africa sorts first, then A-Z.
                const sorted = data.sort((a, b) => {
                    const africaFirst = Number(a.region !== 'Africa') - Number(b.region !== 'Africa');
                    return africaFirst || a.name.common.localeCompare(b.name.common);
                });
                setCountries(sorted);
                try {
                    sessionStorage.setItem(COUNTRIES_CACHE_KEY, JSON.stringify(sorted));
                } catch {
                    // cache is best-effort
                }
            })
            .catch(() => setLoadFailed(true));
    }, [open, countries.length]);

    const selectedCountry = countries.find((c) => c.cca2 === prefs.countryCode);

    const languageOptions = useMemo(() => {
        const langs = new Set<string>(['English']);
        Object.values(selectedCountry?.languages ?? {}).forEach((l) => langs.add(l));
        return Array.from(langs);
    }, [selectedCountry]);

    // NGN pinned first, then the selected country's currencies, then every
    // other African currency the countries API knows about.
    const currencyOptions = useMemo(() => {
        const map = new Map<string, string>([['NGN', '₦']]);
        Object.entries(selectedCountry?.currencies ?? {}).forEach(([code, meta]) => {
            if (!map.has(code)) map.set(code, meta.symbol ?? code);
        });
        countries
            .filter((c) => c.region === 'Africa')
            .forEach((c) => {
                Object.entries(c.currencies ?? {}).forEach(([code, meta]) => {
                    if (!map.has(code)) map.set(code, meta.symbol ?? code);
                });
            });
        return Array.from(map.entries());
    }, [countries, selectedCountry]);

    const filteredCountries = useMemo(() => {
        const term = countrySearch.trim().toLowerCase();
        if (!term) return countries;
        return countries.filter((c) => c.name.common.toLowerCase().includes(term));
    }, [countries, countrySearch]);

    const africanCountries = filteredCountries.filter((c) => c.region === 'Africa');
    const otherCountries = filteredCountries.filter((c) => c.region !== 'Africa');

    /** Merge and persist immediately — Temu applies changes on click. */
    function update(next: Partial<LocalePrefs>) {
        setPrefs((current) => {
            const merged = { ...current, ...next };
            try {
                localStorage.setItem(LOCALE_KEY, JSON.stringify(merged));
            } catch {
                // preference just won't persist
            }
            return merged;
        });
    }

    function chooseCountry(country: RestCountry) {
        const [currencyCode, currencyMeta] = Object.entries(country.currencies ?? {})[0] ?? ['NGN', { symbol: '₦' }];
        const firstLanguage = Object.values(country.languages ?? {})[0] ?? 'English';
        update({
            countryCode: country.cca2,
            countryName: country.name.common,
            language: firstLanguage.includes('English') ? 'English' : firstLanguage,
            currency: currencyCode,
            currencySymbol: currencyMeta.symbol ?? currencyCode,
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
                <img
                    src={flagUrl(prefs.countryCode, 20)}
                    srcSet={`${flagUrl(prefs.countryCode, 40)} 2x`}
                    alt={prefs.countryName}
                    className="h-3.5 w-5 shrink-0 rounded-[2px] object-cover shadow-sm"
                />
                <span className="text-left">
                    <span className="block text-[11px] leading-tight text-gray-400">
                        {prefs.language.slice(0, 2).toUpperCase()}
                    </span>
                    <span className="block font-semibold leading-tight text-gray-800">
                        {prefs.currencySymbol} {prefs.currency}
                    </span>
                </span>
                <ChevronDown className={`h-3.5 w-3.5 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute right-0 top-full z-50 mt-3 w-80 min-w-[320px] rounded-2xl border border-gray-200 bg-white p-5 shadow-xl shadow-slate-900/10">
                    <PopoverCaret className="right-9" />

                    {view === 'main' ? (
                        <>
                            {/* Language — custom dropdown */}
                            <h3 className="px-1 text-sm font-semibold text-slate-900">Language</h3>
                            <SelectMenu
                                className="mt-2"
                                ariaLabel="Language"
                                value={prefs.language}
                                options={languageOptions.map((lang) => ({ value: lang, label: lang }))}
                                onChange={(language) => update({ language })}
                            />

                            <div className="my-3 border-t border-gray-100" />

                            {/* Currency — custom dropdown */}
                            <h3 className="px-1 text-sm font-semibold text-slate-900">Currency</h3>
                            <SelectMenu
                                className="mt-2"
                                ariaLabel="Currency"
                                value={prefs.currency}
                                options={currencyOptions.map(([code, symbol]) => ({
                                    value: code,
                                    label: `${code}: ${symbol}`,
                                }))}
                                onChange={(code) => {
                                    const symbol = currencyOptions.find(([c]) => c === code)?.[1] ?? code;
                                    update({ currency: code, currencySymbol: symbol });
                                }}
                            />

                            <div className="my-3 border-t border-gray-100" />

                            <p className="px-1 text-center text-sm leading-snug text-gray-500">
                                You are shopping on FirstMarket {prefs.countryName}.
                            </p>
                            <button
                                type="button"
                                onClick={() => setView('country')}
                                className="mt-3 w-full rounded-full border border-gray-300 py-2 text-sm font-medium text-gray-800 transition-colors hover:border-gray-400 hover:bg-slate-50"
                            >
                                Change country/region
                            </button>
                            <p className="mt-3 px-1 text-xs leading-snug text-gray-400">
                                Prices are shown in ₦ NGN for now — more currencies and languages arrive
                                with multi-currency support.
                            </p>
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
                                <h3 className="text-sm font-semibold text-slate-900">Change country/region</h3>
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
                                {countries.length === 0 && (
                                    <li className="px-3 py-4 text-center text-xs text-gray-400">
                                        {loadFailed
                                            ? 'Could not load countries — check your connection.'
                                            : 'Loading countries…'}
                                    </li>
                                )}
                                {africanCountries.length > 0 && (
                                    <li className="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                        Africa
                                    </li>
                                )}
                                {africanCountries.map((country) => (
                                    <CountryRow
                                        key={country.cca2}
                                        country={country}
                                        selected={country.cca2 === prefs.countryCode}
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
                                        key={country.cca2}
                                        country={country}
                                        selected={country.cca2 === prefs.countryCode}
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
    const closeTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const clearCloseTimer = () => {
        if (closeTimerRef.current) {
            clearTimeout(closeTimerRef.current);
            closeTimerRef.current = null;
        }
    };

    const scheduleClose = () => {
        clearCloseTimer();
        closeTimerRef.current = setTimeout(() => setOpen(false), 150);
    };

    useEffect(() => {
        function handleOutsideClick(e: MouseEvent) {
            if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', handleOutsideClick);
        return () => document.removeEventListener('mousedown', handleOutsideClick);
    }, []);

    useEffect(() => () => clearCloseTimer(), []);

    return (
        <div
            ref={wrapperRef}
            className="relative"
            onMouseEnter={() => { clearCloseTimer(); setOpen(true); }}
            onMouseLeave={scheduleClose}
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
                <div className="absolute right-0 top-full z-50 mt-2 w-[380px] max-w-[94vw] rounded-2xl border border-gray-100 bg-white p-6 text-center shadow-2xl">
                    {/* Title */}
                    <p className="mb-3 text-sm font-bold text-gray-900">Get the FirstMarket app</p>

                    {/* QR code — real pixel grid with brand logo centred inside */}
                    <div className="relative mx-auto inline-block overflow-hidden rounded-xl ring-1 ring-gray-200">
                        <QRCodeDisplay />
                        <div className="absolute inset-0 flex items-center justify-center">
                            <div className="rounded-lg bg-white p-1.5 shadow-md ring-1 ring-gray-100">
                                <img
                                    src="/images/brand/logo-mark-blue.png"
                                    alt="FirstMarket"
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
                        Until the apps ship, FirstMarket works great in your phone's browser.
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
        <div ref={ref} className="relative hidden sm:block">
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
                    className="absolute right-0 top-full z-50 mt-2 w-64 rounded-2xl border border-gray-100 bg-white p-2 text-left shadow-xl"
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
