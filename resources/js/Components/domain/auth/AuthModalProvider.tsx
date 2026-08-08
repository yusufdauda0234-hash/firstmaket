import AuthModal from '@/Components/domain/auth/AuthModal';
import { AuthModalContext, OpenAuthModal } from '@/Components/domain/auth/auth-modal-context';
import { PageProps } from '@/Types';
import { usePage } from '@inertiajs/react';
import { PropsWithChildren, useCallback, useEffect, useState } from 'react';

/**
 * Owns the sign-in/register modal for the whole app.
 *
 * Mounted at the Inertia root rather than inside PublicLayout, because a
 * page component renders its layout as a *child* — so a page calling
 * useAuthModal() in its own body sits above the provider and would silently
 * get the no-op default. Hoisting it here means "open the sign-in modal"
 * works from a page, a layout or any component in between. AuthModal renders
 * null when closed, so this costs the portal subdomains nothing.
 */
export default function AuthModalProvider({ children }: PropsWithChildren) {
    const { auth } = usePage<PageProps>().props;
    const [open, setOpen] = useState(false);
    // Where to send them after signing in, when the click was really a
    // request to reach a gated page.
    const [intended, setIntended] = useState<string | undefined>(undefined);

    const openAuth = useCallback<OpenAuthModal>((destination) => {
        setIntended(destination);
        setOpen(true);
    }, []);

    // Signing in re-renders with the user set — close the moment that lands.
    useEffect(() => {
        if (auth?.user) setOpen(false);
    }, [auth?.user]);

    // Customers authenticate in the modal only: /login and /register redirect
    // here with ?auth=… . Open the modal once, then drop the flag from the
    // address bar so refresh/back doesn't reopen it.
    useEffect(() => {
        if (auth?.user) return;

        const params = new URLSearchParams(window.location.search);
        if (!params.has('auth')) return;

        setOpen(true);
        params.delete('auth');

        const query = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : ''));
    }, [auth?.user]);

    return (
        <AuthModalContext.Provider value={openAuth}>
            {children}
            <AuthModal open={open} intended={intended} onClose={() => setOpen(false)} />
        </AuthModalContext.Provider>
    );
}
