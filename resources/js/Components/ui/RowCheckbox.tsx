import { useEffect, useRef } from 'react';

interface Props {
    checked: boolean;
    /** Renders the dash state — some but not all rows selected. */
    indeterminate?: boolean;
    onChange: () => void;
    label: string;
}

/**
 * Table selection checkbox.
 *
 * `indeterminate` is a DOM property with no HTML attribute, so it has to be set
 * through a ref — without it a header checkbox showing "checked" for a partial
 * selection reads as "everything is selected", which is exactly the
 * misunderstanding that leads to bulk-approving the wrong rows.
 */
export default function RowCheckbox({ checked, indeterminate = false, onChange, label }: Props) {
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (ref.current) {
            ref.current.indeterminate = indeterminate && !checked;
        }
    }, [indeterminate, checked]);

    return (
        <input
            ref={ref}
            type="checkbox"
            checked={checked}
            onChange={onChange}
            aria-label={label}
            // Stops the row's own click handler opening the review modal.
            onClick={(e) => e.stopPropagation()}
            className="h-4 w-4 cursor-pointer rounded border-gray-300 text-brand-600 focus:ring-2 focus:ring-brand-500/30"
        />
    );
}
