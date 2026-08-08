import { useCallback, useEffect, useState } from 'react';

export type ViewMode = 'table' | 'grid';

/**
 * Remembers whether a listing is shown as a table or a grid of cards.
 *
 * Kept in localStorage rather than the URL: it is a personal working
 * preference, not part of what the page is showing. Putting it in the query
 * string would mean every status tab, pagination link and shared URL had to
 * carry it, and a link sent to a colleague would silently override their own
 * choice.
 *
 * Each listing gets its own key, because the right default differs — product
 * queues benefit from thumbnails, vendor admin work is mostly scanning columns.
 */
export function useViewMode(key: string, fallback: ViewMode = 'table') {
    const storageKey = `fm.view.${key}`;

    // Starts at the fallback so the server and first client render agree;
    // the stored choice is applied straight after mount.
    const [mode, setMode] = useState<ViewMode>(fallback);

    useEffect(() => {
        try {
            const saved = localStorage.getItem(storageKey);
            if (saved === 'table' || saved === 'grid') {
                setMode(saved);
            }
        } catch {
            // Private browsing or a blocked store — the fallback stands.
        }
    }, [storageKey]);

    const choose = useCallback(
        (next: ViewMode) => {
            setMode(next);
            try {
                localStorage.setItem(storageKey, next);
            } catch {
                // Preference simply will not persist.
            }
        },
        [storageKey],
    );

    return { mode, choose };
}
