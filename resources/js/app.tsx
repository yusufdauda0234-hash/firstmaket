import AuthModalProvider from '@/Components/domain/auth/AuthModalProvider';
import CompareTray from '@/Components/domain/catalog/CompareTray';
import PageLoader from '@/Components/ui/PageLoader';
import { ToastProvider } from '@/Components/ui/Toast';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'FirstMaket';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<{ default: React.ComponentType }>('./Pages/**/*.tsx');
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Page not found: ./Pages/${name}.tsx`);
        }

        return page();
    },
    setup({ el, App, props }) {
        /*
         * Toasts and the sign-in modal mount above the page component but
         * below <App>, using Inertia's render-prop.
         *
         * Below <App>, because both providers call usePage() and Inertia
         * only supplies PageContext to what it renders — wrapping <App>
         * itself throws "usePage must be used within the Inertia component".
         *
         * Above the page, because a page renders its layout as a child: a
         * provider living in PublicLayout is unreachable from the page's own
         * body, so useToast()/useAuthModal() there would silently fall back
         * to their no-op defaults and the click would do nothing.
         *
         * Only `key` goes on Component, so navigating remounts the page
         * while the providers (and any visible toast) survive.
         *
         * Note: this replaces Inertia's default children renderer, which
         * also honours a static `Component.layout`. No page uses that — they
         * all render their layout in JSX — so if you ever add one, handle it
         * here.
         */
        createRoot(el).render(
            <App {...props}>
                {({ Component, props: pageProps, key }) => (
                    <ToastProvider>
                        <AuthModalProvider>
                            {/* Outside the keyed Component, so the spinner is
                                not unmounted by the very navigation it is
                                reporting on. */}
                            <PageLoader />
                            <Component key={key} {...pageProps} />
                            {/* Below the page so it overlays it, and outside
                                the key so a navigation does not drop the
                                selection the shopper is building. */}
                            <CompareTray />
                        </AuthModalProvider>
                    </ToastProvider>
                )}
            </App>,
        );
    },
    // Inertia's bundled NProgress bar is off: PageLoader replaces it with a
    // centred spinner, and leaving this on would run both at once.
    progress: false,
});
