import AuthPanel from '@/Components/domain/auth/AuthPanel';
import { useEffect } from 'react';

/**
 * Sign-in/register modal over the public pages (AliExpress pattern) —
 * customers never leave the storefront to authenticate.
 */
export default function AuthModal({
    open,
    intended,
    onClose,
}: {
    open: boolean;
    /** Path to land on after signing in; defaults to the current page. */
    intended?: string;
    onClose: () => void;
}) {
    useEffect(() => {
        if (!open) return;

        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }

        document.addEventListener('keydown', onKey);
        // Restore whatever was set before (e.g. the quick-view modal also
        // locks scrolling) instead of blindly clearing it on close.
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
            className="fixed inset-0 z-[80] flex items-center justify-center overflow-y-auto bg-slate-900/80 p-2 sm:p-4"
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            role="dialog"
            aria-modal="true"
            aria-label="Sign in or register"
        >
            <div className="relative my-auto w-full max-w-[480px] overflow-hidden rounded-3xl bg-white shadow-2xl">
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close"
                    className="absolute right-4 top-4 z-10 rounded-full p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
                >
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>

                <div className="p-4 sm:p-10">
                    <div className="mb-6 text-center">
                        <p className="text-sm font-semibold uppercase tracking-[0.24em] text-gray-500">Welcome back</p>
                        <h1 className="mt-3 text-2xl font-extrabold text-gray-900">Sign in / Register</h1>
                        <p className="mt-2 text-sm text-gray-600">
                            Use your email or phone number to continue. New here? We'll help you register quickly.
                        </p>
                    </div>

                    <AuthPanel intended={intended} />
                </div>
            </div>
        </div>
    );
}