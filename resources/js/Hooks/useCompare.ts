import { useCallback, useEffect, useState } from 'react';

/**
 * The shopper's comparison selection.
 *
 * Backed by localStorage so it survives a reload, and broadcast through a
 * window event so every product card, the tray and the compare page itself
 * agree about what is selected without prop-drilling through the catalogue.
 *
 * This replaces a write-only list that was the cause of a real complaint: the
 * old compare button appended the product to localStorage, never showed what
 * was in there, offered no way to take anything out, and navigated as soon as
 * the list reached two. Because nothing ever cleared it, clicking Compare on
 * a single product opened a page comparing it against three products chosen
 * days earlier — the shopper had picked one thing and been shown four. A
 * selection the shopper cannot see is a selection they cannot consent to, so
 * the list is now visible, toggleable and clearable, and navigation only
 * happens when they ask for it.
 */

const STORAGE_KEY = 'firstmaket.compare';
/** Matches the cap the compare controller enforces server-side. */
export const MAX_COMPARE = 4;
/** Same-tab notification; `storage` only fires in *other* tabs. */
const CHANGED_EVENT = 'firstmaket:compare-changed';

function read(): string[] {
    if (typeof window === 'undefined') return [];

    try {
        const raw = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '[]');

        return Array.isArray(raw)
            ? raw.filter((entry): entry is string => typeof entry === 'string').slice(0, MAX_COMPARE)
            : [];
    } catch {
        // Corrupt or unreadable (private mode, quota) — an empty selection is
        // the safe answer; it can only ever cost a re-pick.
        return [];
    }
}

function write(uuids: string[]) {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(uuids));
    } catch {
        /* Storage unavailable — keep the in-memory state working anyway. */
    }

    window.dispatchEvent(new CustomEvent(CHANGED_EVENT));
}

export function useCompare() {
    const [uuids, setUuids] = useState<string[]>([]);

    // Read after mount rather than in useState's initialiser: the initial
    // render must match the server-rendered markup, and localStorage does not
    // exist there.
    useEffect(() => {
        const sync = () => setUuids(read());

        sync();

        window.addEventListener(CHANGED_EVENT, sync);
        window.addEventListener('storage', sync);

        return () => {
            window.removeEventListener(CHANGED_EVENT, sync);
            window.removeEventListener('storage', sync);
        };
    }, []);

    const has = useCallback((uuid: string) => uuids.includes(uuid), [uuids]);

    /** Add or remove, returning what happened so the caller can say so. */
    const toggle = useCallback((uuid: string): 'added' | 'removed' | 'full' => {
        const current = read();

        if (current.includes(uuid)) {
            write(current.filter((entry) => entry !== uuid));

            return 'removed';
        }

        if (current.length >= MAX_COMPARE) {
            return 'full';
        }

        write([...current, uuid]);

        return 'added';
    }, []);

    const remove = useCallback((uuid: string) => {
        write(read().filter((entry) => entry !== uuid));
    }, []);

    const clear = useCallback(() => write([]), []);

    return { uuids, count: uuids.length, has, toggle, remove, clear, max: MAX_COMPARE };
}
