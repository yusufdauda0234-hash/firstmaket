import { useCompare } from '@/Hooks/useCompare';
import { router } from '@inertiajs/react';
import { GitCompareArrows, X } from 'lucide-react';

/**
 * Floating summary of what the shopper has put up for comparison.
 *
 * The point is that the selection is *visible*. Previously it lived only in
 * localStorage, so there was no way to tell what was queued, no way to drop
 * one, and no way to know why the compare page showed four products when you
 * remembered picking one. Everything the tray offers — the count, the remove
 * buttons, Clear — exists to make that state something the shopper controls.
 *
 * Sits above the account bottom bar on mobile, and hides itself entirely on
 * the compare page, where the table already is the selection.
 */
export default function CompareTray() {
    const { uuids, count, remove, clear, max } = useCompare();

    const onComparePage =
        typeof window !== 'undefined' && window.location.pathname.startsWith('/compare');

    if (count === 0 || onComparePage) {
        return null;
    }

    const ready = count >= 2;

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-0 z-40 p-3 sm:p-4">
            <div className="pointer-events-auto mx-auto flex max-w-3xl flex-wrap items-center gap-x-4 gap-y-3 rounded-2xl border border-gray-200 bg-white/95 p-3 shadow-xl shadow-slate-900/10 backdrop-blur sm:flex-nowrap sm:p-4">
                <span className="flex shrink-0 items-center gap-2 text-sm font-bold text-gray-900">
                    <GitCompareArrows className="h-5 w-5 text-brand-600" />
                    <span className="tabular-nums">
                        {count} of {max}
                    </span>
                    <span className="hidden font-medium text-gray-500 sm:inline">selected</span>
                </span>

                {/* One chip per pick, each removable. A shopper who cannot
                    remove an item cannot correct a mistaken tap. */}
                <ul className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                    {uuids.map((uuid, index) => (
                        <li key={uuid}>
                            <button
                                type="button"
                                onClick={() => remove(uuid)}
                                title="Remove from comparison"
                                className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 transition hover:bg-red-50 hover:text-red-600"
                            >
                                Item {index + 1}
                                <X className="h-3 w-3" />
                            </button>
                        </li>
                    ))}
                </ul>

                <span className="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        onClick={clear}
                        className="rounded-full px-3 py-2 text-xs font-bold text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
                    >
                        Clear
                    </button>
                    <button
                        type="button"
                        disabled={!ready}
                        onClick={() => router.visit(route('catalog.compare', { products: uuids.join(',') }))}
                        title={ready ? undefined : 'Pick one more product to compare'}
                        className="rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400"
                    >
                        {ready ? `Compare ${count}` : 'Pick 1 more'}
                    </button>
                </span>
            </div>
        </div>
    );
}
