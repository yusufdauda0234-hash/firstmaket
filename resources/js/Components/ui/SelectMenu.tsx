import { Check, ChevronDown } from 'lucide-react';
import { ReactNode, useEffect, useRef, useState } from 'react';

export interface SelectMenuOption {
    value: string;
    label: ReactNode;
}

/**
 * Custom dropdown replacement for a native <select>: pill-adjacent button
 * that expands into a scrollable listbox with a checkmark on the selected
 * option. Closes on outside click and Escape.
 */
export default function SelectMenu({
    value,
    options,
    onChange,
    ariaLabel,
    className = '',
}: {
    value: string;
    options: SelectMenuOption[];
    onChange: (value: string) => void;
    ariaLabel: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        function onClickOutside(e: MouseEvent) {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        }

        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') setOpen(false);
        }

        document.addEventListener('mousedown', onClickOutside);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onClickOutside);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const selected = options.find((option) => option.value === value);

    return (
        <div ref={ref} className={`relative ${className}`}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-label={ariaLabel}
                className="flex w-full items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-800 transition-colors hover:border-gray-300"
            >
                <span className="flex min-w-0 items-center gap-2 truncate">{selected?.label ?? value}</span>
                <ChevronDown
                    className={`h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </button>

            {open && (
                <ul
                    role="listbox"
                    className="absolute left-0 right-0 z-10 mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                >
                    {options.map((option) => {
                        const isSelected = option.value === value;
                        return (
                            <li key={option.value}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    onClick={() => {
                                        onChange(option.value);
                                        setOpen(false);
                                    }}
                                    className={`flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-brand-50 ${
                                        isSelected ? 'font-semibold text-brand-700' : 'text-gray-700'
                                    }`}
                                >
                                    <span className="flex min-w-0 items-center gap-2 truncate">{option.label}</span>
                                    {isSelected && <Check className="h-4 w-4 shrink-0" />}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
