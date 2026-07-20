import { cn } from '@/Utils/cn';
import { X } from 'lucide-react';
import { PropsWithChildren, ReactNode, useEffect } from 'react';

type ModalSize = 'sm' | 'md' | 'lg' | 'xl';

const sizeClasses: Record<ModalSize, string> = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-2xl',
};

interface ModalProps {
    open: boolean;
    onClose: () => void;
    title?: ReactNode;
    description?: ReactNode;
    /** Icon chip shown above the title (e.g. an lucide icon element). */
    icon?: ReactNode;
    /** Tailwind classes for the icon chip surface, e.g. "bg-red-50 text-red-600". */
    iconAccent?: string;
    size?: ModalSize;
    /** Footer actions row (buttons). Rendered right-aligned. */
    footer?: ReactNode;
    /** Hide the default close (X) button. */
    hideClose?: boolean;
}

/**
 * Reusable animated dialog: blurred backdrop, spring pop-in, Escape to close,
 * scroll-lock that restores the previous value (so stacked modals behave),
 * and an optional icon-chip header + footer actions row. Modern-marketplace
 * styling shared across the admin and vendor portals.
 */
export default function Modal({
    open,
    onClose,
    title,
    description,
    icon,
    iconAccent = 'bg-brand-50 text-brand-600',
    size = 'md',
    footer,
    hideClose = false,
    children,
}: PropsWithChildren<ModalProps>) {
    useEffect(() => {
        if (!open) return;

        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }

        document.addEventListener('keydown', onKey);
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = previousOverflow;
        };
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div
            className="animate-fadeIn fixed inset-0 z-[80] flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm"
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            role="dialog"
            aria-modal="true"
        >
            <div
                className={cn(
                    // Cap the height so tall content (product forms) scrolls
                    // inside the body instead of growing past the viewport.
                    'animate-popIn relative flex max-h-[calc(100vh-3rem)] w-full flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5',
                    sizeClasses[size],
                )}
            >
                {!hideClose && (
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="absolute right-4 top-4 z-10 flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 active:scale-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/30"
                    >
                        <X className="h-[18px] w-[18px]" />
                    </button>
                )}

                <div className="overflow-y-auto p-5 sm:p-6">
                    {icon && (
                        <div className={cn('flex h-10 w-10 items-center justify-center rounded-xl', iconAccent)}>
                            {icon}
                        </div>
                    )}
                    {title && (
                        <h2 className={cn('text-lg font-extrabold tracking-tight text-gray-900', icon && 'mt-3')}>
                            {title}
                        </h2>
                    )}
                    {description && <p className="mt-1 text-[13px] leading-relaxed text-gray-500">{description}</p>}

                    {children && <div className={cn(title || description ? 'mt-4' : '')}>{children}</div>}
                </div>

                {footer && (
                    <div className="flex flex-wrap justify-end gap-2 border-t border-gray-100 bg-gray-50/60 px-6 py-4">
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}
