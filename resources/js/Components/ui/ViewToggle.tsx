import type { ViewMode } from '@/Hooks/useViewMode';
import { LayoutGrid, Rows3 } from 'lucide-react';

interface Props {
    mode: ViewMode;
    onChange: (mode: ViewMode) => void;
    /** Named in the accessible label so screen readers know which list. */
    label?: string;
}

/**
 * Segmented table/grid switch for admin listings.
 *
 * A radiogroup rather than two buttons: the two options are one choice with
 * one active value, and that is what a screen reader should hear.
 */
export default function ViewToggle({ mode, onChange, label = 'listing' }: Props) {
    const options: { value: ViewMode; icon: typeof Rows3; text: string }[] = [
        { value: 'table', icon: Rows3, text: 'Table' },
        { value: 'grid', icon: LayoutGrid, text: 'Grid' },
    ];

    return (
        <div
            role="radiogroup"
            aria-label={`View ${label} as table or grid`}
            className="inline-flex rounded-full border border-gray-200 bg-white p-0.5 shadow-sm"
        >
            {options.map(({ value, icon: Icon, text }) => {
                const active = mode === value;

                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        aria-label={`${text} view`}
                        onClick={() => onChange(value)}
                        className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold transition ${
                            active
                                ? 'bg-brand-600 text-white shadow-sm'
                                : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'
                        }`}
                    >
                        <Icon className="h-3.5 w-3.5" />
                        <span className="hidden sm:inline">{text}</span>
                    </button>
                );
            })}
        </div>
    );
}
