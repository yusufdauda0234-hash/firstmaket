import AccountDropdown from '@/Components/domain/auth/AccountDropdown';
import AuthModal from '@/Components/domain/auth/AuthModal';
import { AuthModalContext } from '@/Components/domain/auth/auth-modal-context';
import { CategoriesMenu, GetAppPopover, HelpMenu, LocalePopover } from '@/Components/domain/layout/HeaderMenus';
import SearchBox from '@/Components/domain/search/SearchBox';
import Reveal from '@/Components/ui/Reveal';
import { Category, PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, useCallback, useEffect, useState } from 'react';

interface PublicLayoutProps {
    /** Optional — falls back to the globally shared categories prop. */
    categories?: Category[];
}

// Public marketplace pages are always light, like every major marketplace
// (Jumia, AliExpress, Temu, Amazon). Any signed-in customer page can reuse
// this layout too — categories default to the shared Inertia prop.
export default function PublicLayout({ categories: categoriesProp, children }: PropsWithChildren<PublicLayoutProps>) {
    const { auth, flash, supportHotline, categories: sharedCategories } = usePage<PageProps>().props;
    const categories = categoriesProp ?? sharedCategories ?? [];
    const hotline = supportHotline ?? '';
    const [authOpen, setAuthOpen] = useState(false);
    // Only one of the two big header dropdowns (Categories mega menu /
    // search suggestions) may be open at a time.
    const [openModule, setOpenModule] = useState<'search' | 'category' | null>(null);

    const openAuth = useCallback(() => setAuthOpen(true), []);

    // The email/password login posts via Inertia and re-renders this layout
    // with the user set — close the modal the moment they're signed in.
    useEffect(() => {
        if (auth.user) setAuthOpen(false);
    }, [auth.user]);

    // Customers authenticate in the modal only: /login and /register redirect
    // here with ?auth=… . Open the modal once, then drop the flag from the
    // address bar so refresh/back doesn't reopen it.
    useEffect(() => {
        if (auth.user) return;
        const params = new URLSearchParams(window.location.search);
        if (!params.has('auth')) return;
        setAuthOpen(true);
        params.delete('auth');
        const query = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : ''));
    }, [auth.user]);

    return (
        <AuthModalContext.Provider value={openAuth}>
        <div className="flex min-h-screen w-full flex-col bg-gray-50 text-gray-900">
            {/* Promo and utility top bar */}
            <div className="bg-slate-950 text-slate-100">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-2 text-xs sm:text-sm">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-slate-100">
                            <span className="text-base">🚚</span>
                            Free shipping on orders over NGN 15,000
                        </span>
                        <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-slate-100">
                            <span className="text-base">✅</span>
                            Delivery guarantee for all orders
                        </span>
                    </div>
                    <div className="flex flex-wrap items-center gap-4 text-slate-200">
                        <span className="hidden sm:inline">Limited-time offer</span>
                        <Link href={route('vendor.register')} className="font-medium text-brand-yellow hover:text-white">
                            Sell on FirstMaket
                        </Link>
                        <HelpMenu hotline={hotline} />
                        <GetAppPopover />
                    </div>
                </div>
            </div>

            {/* Main header */}
            {/* No overflow-x-hidden here: hiding one axis forces overflow-y
                to auto (CSS spec), which gives the header its own scrollbar
                whenever the account dropdown opens past its bounds. Global
                horizontal overflow is already guarded on html/body. */}
            <header className="sticky top-0 z-40 w-full border-b border-gray-200 bg-white shadow-sm">
                <div className="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-4 px-4 py-3 min-w-0">
                    <Link href={route('home')} className="shrink-0" aria-label="FirstMarkethome">
                        <img src="/images/brand/logo-mark-dark.png" alt="FirstMaket" className="h-10 w-auto" />
                    </Link>

                    <div className="flex flex-1 min-w-0 items-center gap-2">
                        {/* Categories mega menu + search suggestions dropdown */}
                        <CategoriesMenu
                            categories={categories}
                            forceClose={openModule === 'search'}
                            onOpen={() => setOpenModule('category')}
                        />
                        <SearchBox
                            categories={categories}
                            forceClose={openModule === 'category'}
                            onOpen={() => setOpenModule('search')}
                        />
                    </div>

                    <nav className="flex shrink-0 items-center min-w-0 gap-4 text-sm font-medium text-gray-700">
                        <LocalePopover />
                        <AccountDropdown user={auth.user} onOpenAuth={openAuth} />
                        <Link href={route('dashboard')} className="relative hidden items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 hover:border-brand-200 hover:text-brand-600 lg:flex">
                            <CartIcon />
                            <span>Cart</span>
                            <CartBadge />
                        </Link>
                        <button
                            type="button"
                            onClick={openAuth}
                            className="relative inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 transition hover:border-brand-200 hover:text-brand-600 lg:hidden"
                        >
                            <CartIcon />
                            <CartBadge />
                        </button>
                    </nav>
                </div>
        </header>

            {/* Why-choose bar (trust strip) */}
            <div className="border-b border-gray-200 bg-white">
                <div className="scrollbar-none mx-auto flex max-w-7xl items-center justify-center gap-x-8 gap-y-1 overflow-x-auto px-4 py-2 text-xs text-gray-600 sm:text-sm">
                    <span className="flex shrink-0 items-center gap-1.5">
                        <ShieldIcon /> Safe payments via Paystack
                    </span>
                    <span className="flex shrink-0 items-center gap-1.5">
                        <CheckBadgeIcon /> Verified vendors only
                    </span>
                    <span className="hidden shrink-0 items-center gap-1.5 sm:flex">
                        <TruckIcon /> FirstMarketdelivery guarantee
                    </span>
                    <span className="hidden shrink-0 items-center gap-1.5 lg:flex">
                        <ReturnIcon /> 30-day returns
                    </span>
                </div>
            </div>

            {flash.error && (
                <div className="mx-auto mt-3 w-full max-w-7xl px-4 overflow-x-hidden">
                    <p className="rounded-md bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                        {flash.error}
                    </p>
                </div>
            )}

            <main className="flex-1 w-full overflow-x-hidden">{children}</main>

            {/* SEO footer */}
            <footer id="footer-help" className="mt-16 bg-brand-900 text-brand-cream">
                {/* Newsletter / deal alerts band */}
                <div className="border-b border-white/10 bg-brand-800/40">
                    <Reveal className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5 px-4 py-8">
                        <div className="max-w-md">
                            <h3 className="text-xl font-extrabold text-white">Never miss a deal 🔔</h3>
                            <p className="mt-1 text-sm text-brand-100">
                                Get price drops, new arrivals and vendor offers straight to your inbox.
                            </p>
                        </div>
                        <form
                            className="flex w-full max-w-md flex-1 items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                // Deal alerts ride on the account: guests sign up first.
                                if (auth.user) router.get(route('dashboard'));
                                else openAuth();
                            }}
                        >
                            <input
                                type="email"
                                placeholder="Enter your email address"
                                aria-label="Email address for deal alerts"
                                className="w-full min-w-0 flex-1 rounded-full border border-white/20 bg-white/10 px-5 py-3 text-sm text-white transition placeholder:text-brand-200 focus:border-brand-yellow focus:bg-white/15 focus:outline-none focus:ring-2 focus:ring-brand-yellow/30"
                            />
                            <button
                                type="submit"
                                className="shrink-0 rounded-full bg-brand-yellow px-6 py-3 text-sm font-bold text-brand-900 transition hover:-translate-y-0.5 hover:bg-yellow-300 hover:shadow-lg hover:shadow-brand-yellow/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white active:scale-95"
                            >
                                Subscribe
                            </button>
                        </form>
                    </Reveal>
                </div>

                {/* Link columns */}
                <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr_1fr_1fr]">
                    <Reveal>
                        <img
                            src="/images/brand/logo-light-transparent.png"
                            alt="FirstMarket— Just Order. We Deliver"
                            className="h-16 w-auto"
                        />
                        <p className="mt-3 max-w-xs text-sm leading-relaxed text-brand-100">
                            Pay small small or pay at once — FirstMarketdelivers. No loans, no cash
                            withdrawal, just planned ownership.
                        </p>
                        <a
                            href={`tel:${hotline.replace(/[^+\d]/g, '')}`}
                            className="mt-4 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:border-brand-yellow/50 hover:bg-white/10 active:scale-95"
                        >
                            📞 {hotline}
                        </a>
                        <div className="mt-4 flex items-center gap-2" aria-label="Social media — coming soon">
                            {['Facebook', 'Instagram', 'X', 'WhatsApp'].map((network) => (
                                <span
                                    key={network}
                                    title={`${network} — coming soon`}
                                    className="flex h-9 w-9 cursor-default items-center justify-center rounded-full border border-white/15 bg-white/5 text-brand-100 opacity-70 transition duration-200 hover:-translate-y-0.5 hover:scale-110 hover:border-brand-yellow/50 hover:text-brand-yellow hover:opacity-100"
                                >
                                    <SocialGlyph network={network} />
                                </span>
                            ))}
                        </div>
                    </Reveal>

                    <Reveal delay={100}>
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-brand-yellow">
                            Categories
                        </h3>
                        <ul className="mt-4 space-y-2.5 text-sm">
                            {categories.map((category) => (
                                <li key={category.slug}>
                                    <Link
                                        href={route('catalog.index', { category: category.slug })}
                                        className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                    >
                                        {category.name}
                                    </Link>
                                </li>
                            ))}
                            <li>
                                <Link
                                    href={route('catalog.index')}
                                    className="inline-block font-semibold text-white transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                >
                                    Browse everything →
                                </Link>
                            </li>
                        </ul>
                    </Reveal>

                    <Reveal delay={200}>
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-brand-yellow">
                            For Partners
                        </h3>
                        <ul className="mt-4 space-y-2.5 text-sm">
                            <li>
                                <Link href={route('vendor.register')} className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow">
                                    Become a Vendor
                                </Link>
                            </li>
                            <li>
                                <a href={route('vendor.login')} className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow">
                                    Vendor sign in
                                </a>
                            </li>
                            <li>
                                <Link href={route('vendor.register')} className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow">
                                    Zero listing fees
                                </Link>
                            </li>
                            <li>
                                <Link href={route('vendor.register')} className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow">
                                    Instant Paystack payouts
                                </Link>
                            </li>
                        </ul>
                    </Reveal>

                    <Reveal delay={300}>
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-brand-yellow">
                            Support
                        </h3>
                        <ul className="mt-4 space-y-2.5 text-sm">
                            <li>
                                <a
                                    href={`tel:${hotline.replace(/[^+\d]/g, '')}`}
                                    className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                >
                                    Call to order
                                </a>
                            </li>
                            <li>
                                {auth.user ? (
                                    <Link
                                        href={route('orders.index')}
                                        className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                    >
                                        Track my order
                                    </Link>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={openAuth}
                                        className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                    >
                                        Track my order
                                    </button>
                                )}
                            </li>
                            <li>
                                <span className="cursor-default text-brand-100" title="Coming soon">
                                    30-day returns
                                </span>
                            </li>
                            <li>
                                <Link
                                    href={route('faq')}
                                    className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                >
                                    Help center
                                </Link>
                            </li>
                        </ul>
                    </Reveal>

                    <Reveal delay={400}>
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-brand-yellow">
                            Account
                        </h3>
                        <ul className="mt-4 space-y-2.5 text-sm">
                            <li>
                                <button
                                    type="button"
                                    onClick={openAuth}
                                    className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                >
                                    Create account
                                </button>
                            </li>
                            <li>
                                <button
                                    type="button"
                                    onClick={openAuth}
                                    className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                >
                                    Log in
                                </button>
                            </li>
                            <li>
                                {auth.user ? (
                                    <Link
                                        href={route('dashboard')}
                                        className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                    >
                                        My dashboard
                                    </Link>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={openAuth}
                                        className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                    >
                                        My dashboard
                                    </button>
                                )}
                            </li>
                        </ul>
                    </Reveal>
                </div>

                {/* App + payments band */}
                <div className="border-t border-white/10">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-6">
                        <div className="flex items-center gap-3">
                            <span className="text-xs font-semibold uppercase tracking-wide text-brand-200">
                                Get the app
                            </span>
                            <span className="flex cursor-default items-center gap-1.5 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs text-brand-100 opacity-70 transition duration-200 hover:-translate-y-0.5 hover:border-brand-yellow/50 hover:opacity-100">
                                ▶ Google Play
                            </span>
                            <span className="flex cursor-default items-center gap-1.5 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs text-brand-100 opacity-70 transition duration-200 hover:-translate-y-0.5 hover:border-brand-yellow/50 hover:opacity-100">
                                 App Store
                            </span>
                            <span className="text-[10px] text-brand-200">Coming soon</span>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-xs font-semibold uppercase tracking-wide text-brand-200">
                                We accept
                            </span>
                            {['Paystack', 'Visa', 'Mastercard', 'Verve', 'Bank transfer'].map((method) => (
                                <span
                                    key={method}
                                    className="rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white transition duration-200 hover:-translate-y-0.5 hover:border-brand-yellow/50 hover:bg-white/10"
                                >
                                    {method}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Bottom bar */}
                <div className="border-t border-white/10">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-4 text-xs text-brand-200">
                        <span>© {new Date().getFullYear()} FirstMaket. All rights reserved.</span>
                        <span>
                            FirstMarketis not a loan app, bank, or BNPL service. No cash withdrawal.
                        </span>
                        <span>Secure payments by Paystack · Verified vendors · FirstMarketdelivery</span>
                    </div>
                </div>
            </footer>

            <AuthModal open={authOpen} onClose={() => setAuthOpen(false)} />
        </div>
        </AuthModalContext.Provider>
    );
}

/** Minimal line glyphs for the footer social chips (no icon library needed). */
function SocialGlyph({ network }: { network: string }) {
    switch (network) {
        case 'Facebook':
            return (
                <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M13.5 21v-7h2.4l.36-2.8H13.5V9.4c0-.81.22-1.36 1.38-1.36h1.48V5.55A19.6 19.6 0 0 0 14.2 5.4c-2.14 0-3.6 1.3-3.6 3.7v2.1H8.2V14h2.4v7h2.9Z" />
                </svg>
            );
        case 'Instagram':
            return (
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="4.5" />
                    <circle cx="12" cy="12" r="3.5" />
                    <circle cx="16.7" cy="7.3" r="0.9" fill="currentColor" stroke="none" />
                </svg>
            );
        case 'X':
            return (
                <svg className="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M17.7 3H21l-7.3 8.3L22.2 21h-6.6l-5.2-6.1L4.5 21H1.2l7.8-8.9L1.8 3h6.8l4.7 5.5L17.7 3Zm-1.2 16h1.9L7.6 4.9H5.6L16.5 19Z" />
                </svg>
            );
        default: // WhatsApp
            return (
                <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Zm0 1.7a7.3 7.3 0 1 1-3.9 13.5l-.28-.17-2.7.7.73-2.63-.18-.29A7.3 7.3 0 0 1 12 4.7Zm-2.6 3.2c-.16 0-.42.06-.64.3-.22.24-.85.83-.85 2.02 0 1.2.87 2.35 1 2.51.12.16 1.7 2.72 4.2 3.7 2.08.83 2.5.66 2.96.62.45-.04 1.46-.6 1.66-1.17.21-.58.21-1.07.15-1.17-.06-.1-.22-.16-.47-.28-.24-.13-1.46-.72-1.68-.8-.23-.08-.39-.12-.55.12-.16.25-.63.8-.77.96-.14.16-.28.18-.53.06a6.7 6.7 0 0 1-1.97-1.22 7.4 7.4 0 0 1-1.37-1.7c-.14-.25-.01-.38.11-.5.11-.12.25-.3.37-.44.12-.15.16-.25.24-.41.08-.17.04-.31-.02-.44-.06-.12-.54-1.34-.76-1.83-.2-.48-.4-.42-.55-.42l-.53-.01Z" />
                </svg>
            );
    }
}

function CartBadge() {
    return (
        <span className="absolute -right-2 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-brand-yellow text-[10px] font-bold text-brand-900">
            0
        </span>
    );
}

function CartIcon() {
    return (
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M2.25 3h1.39c.51 0 .96.34 1.09.83l.38 1.42m0 0L6.98 12.3a1.12 1.12 0 0 0 1.09.84h9.6c.5 0 .94-.33 1.08-.81l1.94-6.34a1.13 1.13 0 0 0-1.08-1.45H5.11ZM8.63 19.13a.94.94 0 1 1-1.88 0 .94.94 0 0 1 1.88 0Zm9.75 0a.94.94 0 1 1-1.87 0 .94.94 0 0 1 1.87 0Z"
            />
        </svg>
    );
}

function ShieldIcon() {
    return (
        <svg className="h-4 w-4 text-brand-600" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M9 12.75 11.25 15 15 9.75m-3-7.04A12 12 0 0 1 3.6 6.1a12 12 0 0 0 8.4 15.28A12 12 0 0 0 20.4 6.1 12 12 0 0 1 12 2.71Z"
            />
        </svg>
    );
}

function CheckBadgeIcon() {
    return (
        <svg className="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
            />
        </svg>
    );
}

function TruckIcon() {
    return (
        <svg className="h-4 w-4 text-orange-500" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.38a1.13 1.13 0 0 1-1.13-1.13V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.13c.62 0 1.13-.5 1.13-1.13v-3.03c0-.53-.2-1.05-.55-1.44l-2.55-2.83a1.13 1.13 0 0 0-.84-.37H14.25m-12-3h11.25v10.5m0-10.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v8.25"
            />
        </svg>
    );
}

function ReturnIcon() {
    return (
        <svg className="h-4 w-4 text-purple-500" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
    );
}
