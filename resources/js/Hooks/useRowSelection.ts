import { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Checkbox selection for a paginated table.
 *
 * Selection is cleared whenever the visible rows change — moving to page 2 or
 * switching status tab. Carrying a hidden selection across pages is how people
 * end up approving rows they cannot see, and "select all" here honestly means
 * "all on this page".
 */
export function useRowSelection<T extends string>(ids: T[]) {
    const [selected, setSelected] = useState<Set<T>>(new Set());

    // Keyed on the ids themselves so any change of page or filter resets it.
    const fingerprint = ids.join(',');

    useEffect(() => {
        setSelected(new Set());
    }, [fingerprint]);

    const toggle = useCallback((id: T) => {
        setSelected((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }, []);

    const toggleAll = useCallback(() => {
        setSelected((current) => (current.size === ids.length ? new Set<T>() : new Set(ids)));
    }, [ids]);

    const clear = useCallback(() => setSelected(new Set()), []);

    return useMemo(
        () => ({
            selected,
            ids: [...selected],
            count: selected.size,
            isSelected: (id: T) => selected.has(id),
            allSelected: ids.length > 0 && selected.size === ids.length,
            someSelected: selected.size > 0 && selected.size < ids.length,
            toggle,
            toggleAll,
            clear,
        }),
        [selected, ids, toggle, toggleAll, clear],
    );
}
