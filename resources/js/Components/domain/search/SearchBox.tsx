import { Category } from '@/Types';
import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    CornerDownLeft,
    Image as ImageIcon,
    Loader2,
    ScanSearch,
    Search,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const RECENT_KEY = 'fm.recent-searches';
const RECENT_MAX = 8;

function loadRecent(): string[] {
    try {
        return JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]') as string[];
    } catch {
        return [];
    }
}

function saveRecent(entries: string[]) {
    try {
        localStorage.setItem(RECENT_KEY, JSON.stringify(entries.slice(0, RECENT_MAX)));
    } catch {
        // Private browsing may block storage — recent searches just won't persist.
    }
}

interface Suggestion {
    name: string;
    slug: string;
}

export const AVATAR_COLOR_CYCLE = [
    'bg-amber-100 text-amber-700',
    'bg-rose-100 text-rose-700',
    'bg-indigo-100 text-indigo-700',
    'bg-teal-100 text-teal-700',
    'bg-violet-100 text-violet-700',
    'bg-slate-200 text-slate-700',
    'bg-emerald-100 text-emerald-700',
    'bg-sky-100 text-sky-700',
    'bg-fuchsia-100 text-fuchsia-700',
    'bg-lime-100 text-lime-700',
];

export function Avatar({
    title,
    color,
    size = 'sm',
}: {
    title: string;
    color: string;
    size?: 'sm' | 'lg';
}) {
    const sizeClasses = size === 'lg' ? 'h-14 w-14 text-lg' : 'h-6 w-6 text-[11px]';

    return (
        <span className={`flex shrink-0 items-center justify-center rounded-full font-semibold ${sizeClasses} ${color}`}>
            {title.charAt(0).toUpperCase()}
        </span>
    );
}

/** Highlights the matched substring inside a suggestion's name. */
function HighlightedText({ text, query }: { text: string; query: string }) {
    if (!query.trim()) return <>{text}</>;
    const idx = text.toLowerCase().indexOf(query.toLowerCase());
    if (idx === -1) return <>{text}</>;

    return (
        <>
            {text.slice(0, idx)}
            <span className="font-semibold text-brand-600">{text.slice(idx, idx + query.length)}</span>
            {text.slice(idx + query.length)}
        </>
    );
}

interface SearchBoxProps {
    categories: Category[];
    /** When true, closes this dropdown (e.g. the Categories menu just opened). */
    forceClose?: boolean;
    /** Called the moment this component opens its own dropdown. */
    onOpen?: () => void;
}

/**
 * Header search: pill input with a suggestions dropdown — recent searches
 * and category chips when empty, debounced live approved-product results
 * with keyboard navigation once the visitor types, plus a search-by-image
 * panel (AliExpress/Temu interaction, FirstMarketstyling).
 */
export default function SearchBox({ categories, forceClose = false, onOpen }: SearchBoxProps) {
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const [recent, setRecent] = useState<string[]>([]);
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [imagePreview, setImagePreview] = useState<{ url: string; name: string } | null>(null);
    const [showImagePanel, setShowImagePanel] = useState(false);
    const [isDraggingOver, setIsDraggingOver] = useState(false);

    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout>>();
    const listRef = useRef<HTMLUListElement>(null);

    useEffect(() => setRecent(loadRecent()), []);

    // Another header module (e.g. the Categories menu) opened — close this
    // one and drop DOM focus so the input doesn't silently reopen later.
    useEffect(() => {
        if (forceClose) {
            setIsOpen(false);
            setShowImagePanel(false);
            inputRef.current?.blur();
        }
    }, [forceClose]);

    // Release the object URL once it's no longer needed.
    useEffect(() => {
        return () => {
            if (imagePreview) URL.revokeObjectURL(imagePreview.url);
        };
    }, [imagePreview]);

    // Debounced live suggestions from the approved-products endpoint.
    useEffect(() => {
        if (query.trim().length < 2) {
            setSuggestions([]);
            setIsLoading(false);
            return;
        }

        setIsLoading(true);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(async () => {
            try {
                const response = await fetch(
                    route('catalog.suggest', { query: query.trim() }),
                    { headers: { Accept: 'application/json' } },
                );
                if (response.ok) {
                    const body = (await response.json()) as { suggestions: Suggestion[] };
                    setSuggestions(body.suggestions);
                    setActiveIndex(0);
                }
            } catch {
                setSuggestions([]);
            } finally {
                setIsLoading(false);
            }
        }, 250);

        return () => clearTimeout(debounceRef.current);
    }, [query]);

    // Close on outside click.
    useEffect(() => {
        function handleClick(e: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setIsOpen(false);
                setShowImagePanel(false);
            }
        }

        document.addEventListener('mousedown', handleClick);
        return () => document.removeEventListener('mousedown', handleClick);
    }, []);

    // Ctrl+V / Cmd+V pastes an image while the image panel is open.
    useEffect(() => {
        if (!showImagePanel) return;

        function handlePaste(e: ClipboardEvent) {
            const item = Array.from(e.clipboardData?.items ?? []).find((i) => i.type.startsWith('image/'));
            if (item) {
                e.preventDefault();
                handleFile(item.getAsFile());
            }
        }

        document.addEventListener('paste', handlePaste);
        return () => document.removeEventListener('paste', handlePaste);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [showImagePanel]);

    function rememberSearch(term: string) {
        const next = [term, ...recent.filter((r) => r.toLowerCase() !== term.toLowerCase())].slice(0, RECENT_MAX);
        setRecent(next);
        saveRecent(next);
    }

    const openSelf = () => {
        setIsOpen(true);
        setShowImagePanel(false);
        onOpen?.();
    };

    const openImagePanel = () => {
        setShowImagePanel(true);
        setIsOpen(false);
        onOpen?.();
    };

    function handleFile(file: File | null | undefined) {
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        setImagePreview({ url, name: file.name });
        setShowImagePanel(false);
        inputRef.current?.focus();
    }

    function clearImage() {
        if (imagePreview) URL.revokeObjectURL(imagePreview.url);
        setImagePreview(null);
    }

    function submitSearch(term: string, extra: Record<string, string> = {}) {
        if (term !== '') rememberSearch(term);
        setIsOpen(false);
        setShowImagePanel(false);
        router.get(route('catalog.index'), {
            ...(term ? { query: term } : {}),
            ...extra,
        });
    }

    const commitSuggestion = useCallback(
        (suggestion: Suggestion) => {
            rememberSearch(suggestion.name);
            setQuery(suggestion.name);
            setIsOpen(false);
            router.get(route('catalog.product', { product: suggestion.slug }));
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [recent],
    );

    function removeRecent(term: string) {
        const next = recent.filter((r) => r !== term);
        setRecent(next);
        saveRecent(next);
    }

    const typing = query.trim().length >= 2;
    // Keyboard list = suggestions plus the trailing "Search for …" row.
    const totalRows = typing ? suggestions.length + 1 : 0;

    function commitActiveRow() {
        if (!typing) {
            submitSearch(query.trim());
            return;
        }
        const chosen = suggestions[activeIndex];
        if (chosen) commitSuggestion(chosen);
        else submitSearch(query.trim());
    }

    function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
        if (!isOpen && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            openSelf();
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            commitActiveRow();
            return;
        }
        if (e.key === 'Escape') {
            setIsOpen(false);
            inputRef.current?.blur();
            return;
        }
        if (!totalRows) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex((i) => (i + 1) % totalRows);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex((i) => (i - 1 + totalRows) % totalRows);
        }
    }

    // Keep the active row scrolled into view.
    useEffect(() => {
        const activeEl = listRef.current?.querySelector(`[data-index="${activeIndex}"]`);
        activeEl?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex]);

    // Drag & drop onto the "Search by image" panel.
    const handleDragOver = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setIsDraggingOver(true);
    };
    const handleDragLeave = () => setIsDraggingOver(false);
    const handleDrop = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setIsDraggingOver(false);
        handleFile(e.dataTransfer.files?.[0]);
    };

    const recentChips = useMemo(
        () =>
            recent.map((term, i) => ({
                term,
                color: AVATAR_COLOR_CYCLE[i % AVATAR_COLOR_CYCLE.length],
            })),
        [recent],
    );

    const showPopular = isOpen && !typing;

    return (
        <div
            ref={containerRef}
            className="relative flex-1 min-w-0"
            onMouseLeave={() => {
                setIsOpen(false);
                inputRef.current?.blur();
            }}
        >
            {/* Input pill */}
            <div
                className={`flex items-center gap-2 border bg-white py-1.5 pl-4 pr-1.5 shadow-sm transition-all rounded-full ${
                    isOpen || showImagePanel
                        ? 'border-brand-600 ring-4 ring-brand-600/10'
                        : 'border-gray-200 hover:border-gray-300'
                }`}
            >
                {imagePreview && (
                    <span className="flex shrink-0 items-center gap-1.5 rounded-full bg-slate-100 py-1 pl-1 pr-2">
                        <img src={imagePreview.url} alt="" className="h-6 w-6 rounded-full object-cover" />
                        <button
                            type="button"
                            onClick={clearImage}
                            aria-label="Remove image"
                            className="text-gray-400 transition-colors hover:text-gray-600"
                        >
                            <X className="h-3 w-3" />
                        </button>
                    </span>
                )}
                <input
                    ref={inputRef}
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        openSelf();
                    }}
                    onFocus={openSelf}
                    onKeyDown={handleKeyDown}
                    placeholder={imagePreview ? 'Add a keyword (optional)' : 'Search products, brands and categories'}
                    autoComplete="off"
                    className="w-full min-w-0 bg-transparent text-sm text-slate-900 placeholder:text-gray-400 focus:outline-none"
                    role="combobox"
                    aria-expanded={isOpen}
                    aria-controls="search-suggestions"
                    aria-autocomplete="list"
                    aria-label="Search products"
                />
                {isLoading && <Loader2 className="h-4 w-4 shrink-0 animate-spin text-gray-400" />}
                {!isLoading && query && (
                    <button
                        type="button"
                        onClick={() => {
                            setQuery('');
                            setSuggestions([]);
                            inputRef.current?.focus();
                        }}
                        className="shrink-0 rounded-full p-0.5 text-gray-400 transition-colors hover:bg-slate-100 hover:text-gray-600"
                        aria-label="Clear search"
                    >
                        <X className="h-3.5 w-3.5" />
                    </button>
                )}

                {/* Search by image */}
                <button
                    type="button"
                    onClick={openImagePanel}
                    aria-label="Search by image"
                    aria-expanded={showImagePanel}
                    className={`shrink-0 rounded-full p-1 transition-colors hover:bg-slate-100 ${
                        showImagePanel ? 'bg-slate-100 text-gray-700' : 'text-gray-400 hover:text-gray-600'
                    }`}
                >
                    <ScanSearch className="h-5 w-5" />
                </button>
                <input ref={fileInputRef} type="file" accept="image/*" onChange={(e) => {
                    handleFile(e.target.files?.[0]);
                    e.target.value = '';
                }} className="hidden" />

                {/* Submit */}
                <button
                    type="button"
                    onClick={() => submitSearch(query.trim())}
                    aria-label="Search"
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white transition-colors hover:bg-brand-700"
                >
                    <Search className="h-4 w-4" />
                </button>
            </div>

            {/* Search-by-image panel */}
            {showImagePanel && (
                <div className="absolute right-0 z-50 mt-2 w-80 max-w-[90vw] rounded-2xl border border-gray-200 bg-white p-5 shadow-xl shadow-slate-900/10">
                    <div className="mb-1 flex items-start justify-between gap-2">
                        <h3 className="text-base font-semibold text-slate-900">Search by image</h3>
                        <button
                            type="button"
                            onClick={() => setShowImagePanel(false)}
                            aria-label="Close"
                            className="shrink-0 rounded-full p-0.5 text-gray-400 transition-colors hover:bg-slate-100 hover:text-gray-600"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                    <p className="mb-4 text-sm leading-snug text-gray-500">
                        Find what you love with better matches by using an image search.
                    </p>

                    <div
                        onDragOver={handleDragOver}
                        onDragLeave={handleDragLeave}
                        onDrop={handleDrop}
                        className={`flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed py-8 transition-colors ${
                            isDraggingOver ? 'border-brand-400 bg-brand-50' : 'border-gray-200 bg-slate-50'
                        }`}
                    >
                        <ImageIcon className="h-8 w-8 text-gray-300" />
                        <p className="text-sm font-medium text-gray-600">Drag an image here</p>
                        <p className="text-xs text-gray-400">or</p>
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="rounded-full bg-brand-600 px-6 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700"
                        >
                            Upload a photo
                        </button>
                    </div>

                    <p className="mt-3 text-xs leading-snug text-gray-400">
                        *For a quick search hit CTRL+V to paste an image into the search box
                    </p>
                </div>
            )}

            {/* Suggestions panel */}
            {isOpen && (
                <div
                    id="search-suggestions"
                    className="absolute z-50 mt-2 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg shadow-slate-900/5"
                >
                    {showPopular ? (
                        <div className="p-4">
                            {recentChips.length > 0 && (
                                <>
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-sm font-semibold text-slate-800">Recent searches</span>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setRecent([]);
                                                saveRecent([]);
                                            }}
                                            className="text-xs text-brand-600 hover:underline"
                                        >
                                            Clear all
                                        </button>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {recentChips.map(({ term, color }) => (
                                            <span
                                                key={term}
                                                className="flex items-center gap-2 rounded-full border border-slate-100 bg-slate-50 py-1.5 pl-2 pr-1.5 text-sm text-gray-700 transition-colors hover:bg-slate-100"
                                            >
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setQuery(term);
                                                        submitSearch(term);
                                                    }}
                                                    className="flex items-center gap-2"
                                                >
                                                    <Avatar title={term} color={color} />
                                                    <span className="whitespace-nowrap">{term}</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => removeRecent(term)}
                                                    aria-label={`Remove ${term} from recent searches`}
                                                    className="rounded-full p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-600"
                                                >
                                                    <X className="h-3 w-3" />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                </>
                            )}
                            <div className={`text-sm font-semibold text-slate-800 ${recentChips.length > 0 ? 'mb-3 mt-4' : 'mb-3'}`}>
                                Browse categories
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {categories.map((category, i) => (
                                    <button
                                        key={category.slug}
                                        type="button"
                                        onClick={() => {
                                            setIsOpen(false);
                                            router.get(route('catalog.index', { category: category.slug }));
                                        }}
                                        className="flex items-center gap-2 rounded-full border border-slate-100 bg-slate-50 px-3 py-1.5 text-sm text-gray-700 transition-colors hover:bg-slate-100"
                                    >
                                        <Avatar
                                            title={category.name}
                                            color={AVATAR_COLOR_CYCLE[i % AVATAR_COLOR_CYCLE.length]}
                                        />
                                        <span className="whitespace-nowrap">{category.name}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : isLoading && suggestions.length === 0 ? (
                        <div className="px-4 py-6 text-center text-sm text-gray-400">Searching…</div>
                    ) : (
                        <ul ref={listRef} className="max-h-72 overflow-y-auto py-1.5">
                            {suggestions.length > 0 && (
                                <li>
                                    <div className="px-3 pb-1 pt-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                        Products
                                    </div>
                                    <ul>
                                        {suggestions.map((suggestion, idx) => {
                                            const active = idx === activeIndex;
                                            return (
                                                <li key={suggestion.slug} data-index={idx}>
                                                    <button
                                                        type="button"
                                                        onMouseEnter={() => setActiveIndex(idx)}
                                                        onClick={() => commitSuggestion(suggestion)}
                                                        className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm transition-colors ${
                                                            active ? 'bg-brand-50 text-slate-900' : 'text-gray-700'
                                                        }`}
                                                    >
                                                        <span className="truncate">
                                                            <HighlightedText text={suggestion.name} query={query} />
                                                        </span>
                                                        {active && (
                                                            <CornerDownLeft className="h-3.5 w-3.5 shrink-0 text-brand-600" />
                                                        )}
                                                    </button>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </li>
                            )}
                            {suggestions.length === 0 && !isLoading && (
                                <li className="px-4 py-4 text-center text-sm text-gray-400">
                                    No product matches for <span className="text-gray-600">"{query.trim()}"</span>
                                </li>
                            )}
                            <li data-index={suggestions.length}>
                                <button
                                    type="button"
                                    onMouseEnter={() => setActiveIndex(suggestions.length)}
                                    onClick={() => submitSearch(query.trim())}
                                    className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm font-semibold transition-colors ${
                                        activeIndex === suggestions.length
                                            ? 'bg-brand-50 text-brand-700'
                                            : 'text-brand-700'
                                    }`}
                                >
                                    <span className="flex items-center gap-2 truncate">
                                        <Search className="h-3.5 w-3.5 shrink-0 text-gray-400" />
                                        Search for "{query.trim()}"
                                    </span>
                                    {activeIndex === suggestions.length && (
                                        <CornerDownLeft className="h-3.5 w-3.5 shrink-0 text-brand-600" />
                                    )}
                                </button>
                            </li>
                        </ul>
                    )}

                    {/* Footer hints */}
                    {!showPopular && (
                        <div className="flex items-center gap-3 border-t border-slate-100 bg-slate-50 px-3 py-1.5 text-[11px] text-gray-400">
                            <span className="flex items-center gap-1">
                                <ArrowUp className="h-3 w-3" />
                                <ArrowDown className="h-3 w-3" />
                                navigate
                            </span>
                            <span className="flex items-center gap-1">
                                <CornerDownLeft className="h-3 w-3" />
                                select
                            </span>
                            <span>esc to close</span>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
