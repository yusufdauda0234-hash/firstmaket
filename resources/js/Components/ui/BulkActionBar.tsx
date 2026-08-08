import { Loader2, X } from 'lucide-react';
import { useState } from 'react';

export interface BulkAction {
    /** Button text. The selected count is appended automatically. */
    label: string;
    tone?: 'primary' | 'danger' | 'neutral';
    /**
     * Collect a free-text reason before running. Used where the person on the
     * other end has to act on the decision — a rejection or a suspension with
     * no reason given is useless to them.
     */
    needsReason?: boolean;
    /** Placeholder for the reason field, when one is collected. */
    reasonPlaceholder?: string;
    run: (reason: string) => void;
}

interface Props {
    count: number;
    /** Singular noun for the rows, e.g. "listing". */
    noun: string;
    /** Plural, when adding "s" is wrong — "category" -> "categories". */
    plural?: string;
    actions: BulkAction[];
    onClear: () => void;
    processing?: boolean;
}

const TONE: Record<string, string> = {
    primary: 'bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-gray-200 disabled:text-gray-400',
    danger: 'bg-red-600 text-white hover:bg-red-700 disabled:bg-gray-200 disabled:text-gray-400',
    neutral:
        'border border-gray-200 text-gray-700 hover:border-gray-300 hover:bg-gray-50 disabled:opacity-50',
};

/**
 * Appears once rows are ticked. Sticky at the bottom so the selection and the
 * action stay together however far the operator has scrolled.
 *
 * The action list is passed in rather than hardcoded, because the same
 * selection UI serves approve/reject queues, activate/deactivate lists and
 * account moderation — one bar, four tables.
 */
export default function BulkActionBar({ count, noun, plural, actions, onClear, processing = false }: Props) {
    const [asking, setAsking] = useState<BulkAction | null>(null);
    const [reason, setReason] = useState('');

    if (count === 0) {
        return null;
    }

    const label = count === 1 ? noun : (plural ?? `${noun}s`);

    return (
        <div className="sticky bottom-4 z-30 mt-4">
            <div className="mx-auto flex max-w-3xl flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-xl shadow-slate-900/10">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="text-sm font-bold text-gray-900">
                        {count} {label} selected
                    </span>

                    <button
                        type="button"
                        onClick={onClear}
                        className="inline-flex items-center gap-1 text-xs font-semibold text-gray-500 hover:text-gray-700"
                    >
                        <X className="h-3.5 w-3.5" /> Clear
                    </button>

                    <div className="ml-auto flex flex-wrap gap-2">
                        {actions.map((action) => (
                            <button
                                key={action.label}
                                type="button"
                                disabled={processing}
                                onClick={() => {
                                    if (action.needsReason) {
                                        setAsking(asking?.label === action.label ? null : action);
                                        setReason('');
                                    } else {
                                        action.run('');
                                    }
                                }}
                                className={`inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold transition ${
                                    TONE[action.tone ?? 'neutral']
                                }`}
                            >
                                {processing && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                                {action.label} {count}
                            </button>
                        ))}
                    </div>
                </div>

                {asking !== null && (
                    <div className="border-t border-gray-100 pt-3">
                        <label htmlFor="bulk-reason" className="mb-1.5 block text-xs font-bold text-gray-700">
                            Reason — sent to every {noun} selected
                        </label>
                        <div className="flex flex-wrap gap-2">
                            <input
                                id="bulk-reason"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder={asking.reasonPlaceholder ?? 'The same reason applies to all of them'}
                                autoFocus
                                className="min-w-[240px] flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            />
                            <button
                                type="button"
                                disabled={processing || reason.trim() === ''}
                                onClick={() => {
                                    asking.run(reason.trim());
                                    setReason('');
                                    setAsking(null);
                                }}
                                className={`rounded-full px-4 py-2 text-xs font-bold transition ${TONE[asking.tone ?? 'danger']}`}
                            >
                                {asking.label} {count}
                            </button>
                        </div>
                        <p className="mt-1.5 text-xs text-gray-400">
                            Keep it general — all {count} {label} get this same message.
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
