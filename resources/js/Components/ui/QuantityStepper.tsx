import { Minus, Plus } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Props {
    value: number;
    max: number;
    min?: number;
    disabled?: boolean;
    onChange: (quantity: number) => void;
    /** Distinguishes the inputs when several steppers share a page. */
    label?: string;
}

/** How long typing must pause before the quantity is sent. */
const COMMIT_DELAY_MS = 700;

/**
 * Minus / editable number / plus.
 *
 * The number is a real input so a shopper buying twelve of something can type
 * "12" instead of tapping plus eleven times. Clamping mid-keystroke would
 * fight them — turning "12" into "1" the moment the first digit lands — so
 * the draft is kept local and committed once typing stops, and immediately on
 * blur or Enter. Waiting only for blur meant a typed number appeared to do
 * nothing until you clicked elsewhere; a short pause is what a shopper reads
 * as "it took my number".
 */
export default function QuantityStepper({
    value,
    max,
    min = 1,
    disabled = false,
    onChange,
    label = 'Quantity',
}: Props) {
    const [draft, setDraft] = useState(String(value));
    const [editing, setEditing] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    // The debounced commit disables the input, which blurs it, which would
    // otherwise fire a second identical request before the first lands.
    const lastSent = useRef<number | null>(null);

    // Follow the source of truth whenever it changes underneath us (server
    // response, or another stepper) — but never while the user is mid-type.
    useEffect(() => {
        if (!editing) setDraft(String(value));
        // The server has caught up, so the next edit is free to send again.
        lastSent.current = null;
    }, [value, editing]);

    const cancelPending = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
    }, []);

    // A pending commit must not fire after the row has gone.
    useEffect(() => cancelPending, [cancelPending]);

    const clamp = (n: number) => Math.min(max, Math.max(min, n));

    /** @param settle  Whether to drop out of editing mode as well. */
    const commit = (text: string, settle: boolean) => {
        cancelPending();

        if (settle) setEditing(false);

        const parsed = parseInt(text, 10);

        if (Number.isNaN(parsed)) {
            if (settle) setDraft(String(value));

            return;
        }

        const next = clamp(parsed);
        if (settle) setDraft(String(next));

        if (next !== value && next !== lastSent.current) {
            lastSent.current = next;
            onChange(next);
        }
    };

    const type = (text: string) => {
        setDraft(text);
        cancelPending();

        // An empty field is mid-edit, not a request for zero.
        if (text === '') return;

        timer.current = setTimeout(() => commit(text, false), COMMIT_DELAY_MS);
    };

    return (
        <div className="flex items-center gap-1 rounded-full border border-gray-200 px-1.5 py-1">
            <button
                type="button"
                onClick={() => {
                    cancelPending();
                    onChange(clamp(value - 1));
                }}
                disabled={disabled || value <= min}
                aria-label="Decrease quantity"
                className="flex h-7 w-7 items-center justify-center rounded-full text-gray-600 transition hover:bg-slate-100 active:scale-90 disabled:cursor-not-allowed disabled:opacity-40"
            >
                <Minus className="h-3.5 w-3.5" />
            </button>

            <input
                type="text"
                inputMode="numeric"
                pattern="[0-9]*"
                value={draft}
                disabled={disabled}
                aria-label={label}
                onFocus={(e) => {
                    setEditing(true);
                    e.target.select();
                }}
                onChange={(e) => type(e.target.value.replace(/[^\d]/g, ''))}
                onBlur={(e) => commit(e.target.value, true)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        e.currentTarget.blur();
                    }
                    if (e.key === 'Escape') {
                        cancelPending();
                        setDraft(String(value));
                        setEditing(false);
                        e.currentTarget.blur();
                    }
                }}
                className="w-10 border-0 bg-transparent p-0 text-center text-sm font-bold tabular-nums text-gray-900 focus:outline-none focus:ring-0 disabled:opacity-50"
            />

            <button
                type="button"
                onClick={() => {
                    cancelPending();
                    onChange(clamp(value + 1));
                }}
                disabled={disabled || value >= max}
                aria-label="Increase quantity"
                className="flex h-7 w-7 items-center justify-center rounded-full text-gray-600 transition hover:bg-slate-100 active:scale-90 disabled:cursor-not-allowed disabled:opacity-40"
            >
                <Plus className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}
