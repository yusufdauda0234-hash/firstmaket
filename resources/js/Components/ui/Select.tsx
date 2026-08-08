import { cn } from '@/Utils/cn';
import { Check, ChevronDown } from 'lucide-react';
import {
    ChangeEvent,
    Children,
    isValidElement,
    KeyboardEvent as ReactKeyboardEvent,
    ReactNode,
    SelectHTMLAttributes,
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';

interface Option {
    value: string;
    label: ReactNode;
    text: string;
    disabled: boolean;
}

/**
 * Dropdown with a fully styled option list.
 *
 * Keeps the native `<select>` API — `value`, `onChange(e)` reading
 * `e.target.value`, and `<option>` children — so every call site is unchanged,
 * but renders its own listbox: the browser's option list cannot be styled and
 * looked foreign next to the rest of the admin.
 *
 * The list is portalled to the body and positioned from the trigger's rect.
 * Several of these sit inside cards with `overflow-hidden`, which would clip an
 * absolutely positioned menu; a portal cannot be clipped by an ancestor.
 *
 * One honest trade-off: native `required` no longer takes part in browser form
 * validation, because there is no real `<select>` left to validate. Every form
 * here is validated server-side, which is what actually protects the data.
 */
export const Select = ({
    className,
    children,
    value,
    onChange,
    disabled,
    id,
    name,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement>) => {
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const [rect, setRect] = useState<DOMRect | null>(null);

    const triggerRef = useRef<HTMLButtonElement>(null);
    const listRef = useRef<HTMLUListElement>(null);
    const typeahead = useRef({ term: '', at: 0 });

    // Read the options straight out of the <option> children, so call sites
    // keep writing ordinary JSX.
    const options = useMemo<Option[]>(() => {
        const collected: Option[] = [];

        Children.forEach(children, (child) => {
            if (!isValidElement(child) || child.type !== 'option') {
                return;
            }

            const childProps = child.props as { value?: string; children?: ReactNode; disabled?: boolean };
            const label = childProps.children;

            collected.push({
                value: String(childProps.value ?? ''),
                label,
                // Plain text drives typeahead and the closed-state label.
                text: typeof label === 'string' ? label : String(childProps.value ?? ''),
                disabled: Boolean(childProps.disabled),
            });
        });

        return collected;
    }, [children]);

    const current = String(value ?? '');
    const selected = options.find((option) => option.value === current);
    const selectedIndex = options.findIndex((option) => option.value === current);

    /** Fire the caller's handler with something shaped like a change event. */
    const choose = useCallback(
        (option: Option) => {
            if (option.disabled) {
                return;
            }

            setOpen(false);
            triggerRef.current?.focus();

            onChange?.({
                target: { value: option.value, name: name ?? '' },
                currentTarget: { value: option.value, name: name ?? '' },
            } as unknown as ChangeEvent<HTMLSelectElement>);
        },
        [name, onChange],
    );

    const place = useCallback(() => {
        if (triggerRef.current) {
            setRect(triggerRef.current.getBoundingClientRect());
        }
    }, []);

    useLayoutEffect(() => {
        if (open) {
            place();
            setActiveIndex(selectedIndex >= 0 ? selectedIndex : 0);
        }
    }, [open, place, selectedIndex]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (e: MouseEvent) => {
            const target = e.target as Node;
            if (!triggerRef.current?.contains(target) && !listRef.current?.contains(target)) {
                setOpen(false);
            }
        };

        // Reposition rather than close: a menu that vanishes on the first
        // scroll nudge feels broken.
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        document.addEventListener('mousedown', onPointerDown);

        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
            document.removeEventListener('mousedown', onPointerDown);
        };
    }, [open, place]);

    // Keep the highlighted row in view when arrowing through a long list.
    useEffect(() => {
        if (!open || activeIndex < 0) {
            return;
        }

        listRef.current?.querySelectorAll('[role="option"]')[activeIndex]?.scrollIntoView({ block: 'nearest' });
    }, [open, activeIndex]);

    const step = useCallback(
        (from: number, direction: 1 | -1) => {
            for (let i = 1; i <= options.length; i++) {
                const next = (((from + direction * i) % options.length) + options.length) % options.length;
                if (!options[next]?.disabled) {
                    return next;
                }
            }

            return from;
        },
        [options],
    );

    const onKeyDown = (e: ReactKeyboardEvent) => {
        if (disabled) {
            return;
        }

        if (!open && ['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(e.key)) {
            e.preventDefault();
            setOpen(true);
            return;
        }

        if (!open) {
            return;
        }

        switch (e.key) {
            case 'Escape':
                e.preventDefault();
                setOpen(false);
                triggerRef.current?.focus();
                break;
            case 'Tab':
                setOpen(false);
                break;
            case 'ArrowDown':
                e.preventDefault();
                setActiveIndex((i) => step(i, 1));
                break;
            case 'ArrowUp':
                e.preventDefault();
                setActiveIndex((i) => step(i, -1));
                break;
            case 'Home':
                e.preventDefault();
                setActiveIndex(step(-1, 1));
                break;
            case 'End':
                e.preventDefault();
                setActiveIndex(step(options.length, -1));
                break;
            case 'Enter':
            case ' ':
                e.preventDefault();
                if (options[activeIndex]) {
                    choose(options[activeIndex]);
                }
                break;
            default:
                // Typeahead — indispensable on a 37-item state list.
                if (e.key.length === 1 && !e.metaKey && !e.ctrlKey) {
                    const now = Date.now();
                    typeahead.current.term =
                        now - typeahead.current.at > 800 ? e.key : typeahead.current.term + e.key;
                    typeahead.current.at = now;

                    const term = typeahead.current.term.toLowerCase();
                    const found = options.findIndex(
                        (option) => !option.disabled && option.text.toLowerCase().startsWith(term),
                    );

                    if (found >= 0) {
                        setActiveIndex(found);
                    }
                }
        }
    };

    const listId = id ? `${id}-listbox` : undefined;

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                id={id}
                role="combobox"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={listId}
                aria-label={props['aria-label']}
                aria-labelledby={props['aria-labelledby']}
                disabled={disabled}
                onClick={() => !disabled && setOpen((v) => !v)}
                onKeyDown={onKeyDown}
                className={cn(
                    'flex w-full items-center justify-between gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm text-gray-900 shadow-sm transition',
                    'focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20',
                    'disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400',
                    className,
                )}
            >
                <span className="min-w-0 truncate">{selected?.label ?? <span>&nbsp;</span>}</span>
                <ChevronDown
                    aria-hidden="true"
                    className={`h-4 w-4 shrink-0 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </button>

            {open &&
                rect &&
                createPortal(
                    <ul
                        ref={listRef}
                        id={listId}
                        role="listbox"
                        tabIndex={-1}
                        onKeyDown={onKeyDown}
                        style={{
                            position: 'fixed',
                            top: rect.bottom + 6,
                            left: rect.left,
                            width: rect.width,
                            // Never overflow the viewport, however far down the
                            // page the trigger sits.
                            maxHeight: Math.max(160, window.innerHeight - rect.bottom - 24),
                        }}
                        className="z-[100] overflow-y-auto rounded-xl border border-gray-200 bg-white py-1 shadow-xl shadow-slate-900/10"
                    >
                        {options.map((option, index) => {
                            const isSelected = option.value === current;
                            const isActive = index === activeIndex;

                            return (
                                <li key={`${option.value}-${index}`}>
                                    <div
                                        role="option"
                                        aria-selected={isSelected}
                                        aria-disabled={option.disabled || undefined}
                                        onMouseEnter={() => !option.disabled && setActiveIndex(index)}
                                        onClick={() => choose(option)}
                                        className={cn(
                                            'flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm transition-colors',
                                            option.disabled && 'cursor-not-allowed text-gray-300',
                                            !option.disabled && isActive && 'bg-brand-50',
                                            !option.disabled &&
                                                (isSelected ? 'font-semibold text-brand-700' : 'text-gray-700'),
                                        )}
                                    >
                                        <span className="min-w-0 truncate">{option.label}</span>
                                        {isSelected && <Check className="h-4 w-4 shrink-0 text-brand-600" />}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>,
                    document.body,
                )}
        </>
    );
};

Select.displayName = 'Select';
