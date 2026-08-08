import { createContext, PropsWithChildren, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

export type ToastVariant = 'success' | 'error' | 'info';

interface Toast {
    id: number;
    message: string;
    variant: ToastVariant;
}

type ShowToast = (message: string, variant?: ToastVariant) => void;

/**
 * Defaults to a no-op so a component can call useToast() without caring
 * whether the layout it happens to be rendered in provides the host.
 */
const ToastContext = createContext<ShowToast>(() => {});

export function useToast(): ShowToast {
    return useContext(ToastContext);
}

const VISIBLE_MS = 2600;

/**
 * Top-centre toasts, the pattern every marketplace uses to confirm
 * "saved to cart" without yanking the shopper off the grid they are
 * browsing. Deliberately imperative rather than driven off Inertia's flash
 * bag: add-to-cart fires the same message repeatedly, and a flash-watching
 * effect cannot tell a repeat from a re-render.
 */
export function ToastProvider({ children }: PropsWithChildren) {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const nextId = useRef(0);

    const show = useCallback<ShowToast>((message, variant = 'success') => {
        const id = nextId.current++;

        setToasts((current) => [...current.slice(-2), { id, message, variant }]);
        window.setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), VISIBLE_MS);
    }, []);

    // Stable identity: a new function each render would re-render every
    // consumer of the context on every parent render.
    const value = useMemo(() => show, [show]);

    return (
        <ToastContext.Provider value={value}>
            {children}
            <ToastHost toasts={toasts} onDismiss={(id) => setToasts((c) => c.filter((t) => t.id !== id))} />
        </ToastContext.Provider>
    );
}

function ToastHost({ toasts, onDismiss }: { toasts: Toast[]; onDismiss: (id: number) => void }) {
    if (toasts.length === 0) return null;

    return (
        <div
            className="pointer-events-none fixed inset-x-0 top-4 z-[100] flex flex-col items-center gap-2 px-4"
            role="status"
            aria-live="polite"
        >
            {toasts.map((toast) => (
                <button
                    key={toast.id}
                    type="button"
                    onClick={() => onDismiss(toast.id)}
                    className={`animate-toastIn pointer-events-auto flex max-w-sm items-center gap-2.5 rounded-full py-2.5 pl-3 pr-4 text-sm font-semibold shadow-lg shadow-slate-900/10 ring-1 backdrop-blur ${
                        toast.variant === 'error'
                            ? 'bg-red-50/95 text-red-800 ring-red-200'
                            : toast.variant === 'info'
                              ? 'bg-slate-900/90 text-white ring-white/10'
                              : 'bg-white/95 text-gray-900 ring-gray-200'
                    }`}
                >
                    <ToastGlyph variant={toast.variant} />
                    <span className="text-left">{toast.message}</span>
                </button>
            ))}
        </div>
    );
}

function ToastGlyph({ variant }: { variant: ToastVariant }) {
    if (variant === 'error') {
        return (
            <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-600 text-white">
                <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </span>
        );
    }

    return (
        <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-600 text-white">
            <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" strokeWidth={3.5} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
        </span>
    );
}

/**
 * Surfaces a server flash message as a toast exactly once per value change —
 * for redirects that carry a message the page itself did not trigger.
 */
export function useFlashToast(message: string | undefined, variant: ToastVariant = 'success') {
    const toast = useToast();
    const lastShown = useRef<string | undefined>(undefined);

    useEffect(() => {
        if (!message || message === lastShown.current) return;
        lastShown.current = message;
        toast(message, variant);
    }, [message, variant, toast]);
}
