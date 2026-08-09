import PaymentMarks from '@/Components/ui/PaymentMarks';
import AccountDropdown from '@/Components/domain/auth/AccountDropdown';
import { useAuthModal } from '@/Components/domain/auth/auth-modal-context';
import { CategoriesMenu, GetAppPopover, HelpMenu, LocalePopover } from '@/Components/domain/layout/HeaderMenus';
import SearchBox from '@/Components/domain/search/SearchBox';
import Reveal from '@/Components/ui/Reveal';
import { useFlashToast } from '@/Components/ui/Toast';
import { Category, PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, useState } from 'react';
import { useTranslation } from '@/Hooks/useI18n';
import { formatNairaFromKobo } from '@/Utils/money';

interface PublicLayoutProps {
    /** Optional — falls back to the globally shared categories prop. */
    categories?: Category[];
}

/**
 * Public marketplace pages are always light, like every major marketplace
 * (Jumia, AliExpress, Temu, Amazon). Any signed-in customer page can reuse
 * this layout too — categories default to the shared Inertia prop.
 *
 * The sign-in modal and the toast host are mounted at the Inertia root
 * (resources/js/app.tsx), not here, so page components can reach them too.
 */
export default function PublicLayout({ categories: categoriesProp, children }: PropsWithChildren<PublicLayoutProps>) {
    const { t } = useTranslation();
    const {
        auth,
        flash,
        supportHotline,
        categories: sharedCategories,
        cartCount,
        freeDeliveryFromKobo,
        legalLinks,
    } = usePage<PageProps>().props;
    const categories = categoriesProp ?? sharedCategories ?? [];
    const hotline = supportHotline ?? '';
    const openAuth = useAuthModal();
    // Only one of the two big header dropdowns (Categories mega menu /
    // search suggestions) may be open at a time.
    const [openModule, setOpenModule] = useState<'search' | 'category' | null>(null);

    // Messages that arrive on a redirect (order placed, cart merged) surface
    // as toasts too, so there is one confirmation language across the site.
    useFlashToast(flash.success);

    return (
        <div className="flex min-h-screen w-full flex-col bg-gray-50 text-gray-900">
            {/* Promo and utility top bar */}
            <div className="bg-slate-950 text-slate-100">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-3 gap-y-2 px-4 py-2 text-xs sm:text-sm">
                    <div className="scrollbar-none flex min-w-0 max-w-full flex-1 items-center gap-3 overflow-x-auto whitespace-nowrap">
                        {/* Only shown when a delivery rate actually offers it.
                            This was a hardcoded "NGN 15,000" that kept
                            promising free delivery after the rates screen had
                            been set to charge on every order. */}
                        {freeDeliveryFromKobo > 0 && (
                            <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-slate-100">
                                <span className="text-base">🚚</span>
                                {t('Free shipping on orders over :amount', {
                                    amount: formatNairaFromKobo(freeDeliveryFromKobo),
                                })}
                            </span>
                        )}
                        <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-slate-100">
                            <span className="text-base">✅</span>
                            {t('Delivery guarantee for all orders')}
                        </span>
                    </div>
                    <div className="ml-auto flex shrink-0 items-center gap-3 text-slate-200 sm:gap-4">
                        <span className="hidden lg:inline">{t('Limited-time offer')}</span>
                        <Link href={route('vendor.register')} className="hidden font-medium text-brand-yellow hover:text-white sm:inline">
                            {t('Sell on FirstMaket')}
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
                <div className="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-3 px-4 py-3 min-w-0 lg:gap-4">
                    <Link href={route('home')} className="order-1 shrink-0" aria-label="FirstMaket home">
                        <img src="/images/brand/logo-mark-dark.png" alt="FirstMaket" className="h-12 w-auto" />
                    </Link>

                    <div className="order-3 flex min-w-0 basis-full items-center gap-2 lg:order-2 lg:flex-1 lg:basis-auto">
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

                    <nav className="order-2 ml-auto flex min-w-0 shrink-0 items-center gap-2 text-sm font-medium text-gray-700 lg:order-3 lg:ml-0 lg:gap-4">
                        <LocalePopover />
                        <AccountDropdown user={auth.user} onOpenAuth={openAuth} />
                        {/* Guests get a cart too — the sign-in gate is at
                            checkout, not here. Same tab: unlike a product
                            page, the cart is a destination, not something you
                            glance at and come back from. */}
                        <Link
                            href={route('cart.index')}
                            aria-label={`Cart, ${cartCount} item${cartCount === 1 ? '' : 's'}`}
                            className="relative hidden items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 transition hover:border-brand-200 hover:text-brand-600 lg:flex"
                        >
                            <CartIcon />
                            <span>{t('Cart')}</span>
                            <CartBadge count={cartCount} />
                        </Link>
                        <Link
                            href={route('cart.index')}
                            aria-label={`Cart, ${cartCount} item${cartCount === 1 ? '' : 's'}`}
                            className="relative inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 transition hover:border-brand-200 hover:text-brand-600 lg:hidden"
                        >
                            <CartIcon />
                            <CartBadge count={cartCount} />
                        </Link>
                    </nav>
                </div>
        </header>

            {/* Why-choose bar (trust strip) */}
            <div className="border-b border-gray-200 bg-white">
                <div className="scrollbar-none mx-auto flex max-w-7xl items-center justify-center gap-x-8 gap-y-1 overflow-x-auto px-4 py-2 text-xs text-gray-600 sm:text-sm">
                    <span className="flex shrink-0 items-center gap-1.5">
                        <ShieldIcon /> {t('Safe payments via Paystack')}
                    </span>
                    <span className="flex shrink-0 items-center gap-1.5">
                        <CheckBadgeIcon /> {t('Verified vendors only')}
                    </span>
                    <span className="hidden shrink-0 items-center gap-1.5 sm:flex">
                        <TruckIcon /> FirstMaket delivery guarantee
                    </span>
                    <span className="hidden shrink-0 items-center gap-1.5 lg:flex">
                        <ReturnIcon /> {t('30-day returns')}
                    </span>
                </div>
            </div>

            {flash.error && (
                <div className="mx-auto mt-3 w-full max-w-7xl px-4 overflow-x-clip">
                    <p className="rounded-md bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                        {flash.error}
                    </p>
                </div>
            )}

            {/* `clip`, not `hidden`. Hiding one axis forces the other to
                `auto`, which makes this a scroll container — and any `sticky`
                child inside a page then anchors to <main> instead of the
                viewport, so it scrolls away. `clip` still cuts horizontal
                overflow. */}
            <main className="flex-1 w-full overflow-x-clip">{children}</main>

            {/* SEO footer */}
            <footer id="footer-help" className="mt-16 bg-brand-900 text-brand-cream">
                {/* Newsletter / deal alerts band */}
                <div className="border-b border-white/10 bg-brand-800/40">
                    <Reveal className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5 px-4 py-8">
                        <div className="max-w-md">
                            <h3 className="text-xl font-extrabold text-white">{t('Never miss a deal')} 🔔</h3>
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
                                {t('Subscribe')}
                            </button>
                        </form>
                    </Reveal>
                </div>

                {/* Link columns */}
                <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr_1fr_1fr]">
                    <Reveal>
                        <img
                            src="/images/brand/logo-full-light.png"
                            alt="FirstMaket— Just Order. We Deliver"
                            className="h-20 w-auto"
                        />
                        <p className="mt-3 max-w-xs text-sm leading-relaxed text-brand-100">
                            Pay small small or pay at once — FirstMaket delivers. No loans, no cash
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
                                    {t('Become a Vendor')}
                                </Link>
                            </li>
                            <li>
                                <a href={route('vendor.login')} className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow">
                                    {t('Vendor sign in')}
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
                                    {t('Call to order')}
                                </a>
                            </li>
                            <li>
                                {auth.user ? (
                                    <Link
                                        href={route('orders.index')}
                                        className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                    >
                                        {t('Track my order')}
                                    </Link>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => openAuth()}
                                        className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                    >
                                        {t('Track my order')}
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
                                    {t('Help center')}
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
                                    onClick={() => openAuth()}
                                    className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                >
                                    {t('Create account')}
                                </button>
                            </li>
                            <li>
                                <button
                                    type="button"
                                    onClick={() => openAuth()}
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
                                        {t('My dashboard')}
                                    </Link>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => openAuth()}
                                        className="inline-block text-brand-100 transition-all duration-200 hover:translate-x-1 hover:text-brand-yellow"
                                    >
                                        {t('My dashboard')}
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
                                {t('Get the app')}
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
                            {/* Same marks as the cart's "Pay with" row, so the
                                two never drift apart. */}
                            <PaymentMarks compact />
                            <span className="rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white">
                                Bank transfer
                            </span>
                        </div>
                    </div>
                </div>

                {/* Legal links. Driven by what the admin has published, so a
                    new policy page appears here on its own and an unpublished
                    one stops being linked rather than 404ing. */}
                {(legalLinks ?? []).length > 0 && (
                    <div className="border-t border-white/10">
                        <nav
                            aria-label="Legal"
                            className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-5 gap-y-2 px-4 py-4 text-xs"
                        >
                            {(legalLinks ?? []).map((link) => (
                                <Link
                                    key={link.url}
                                    href={link.url}
                                    className="text-brand-200 transition hover:text-brand-yellow"
                                >
                                    {link.title}
                                </Link>
                            ))}
                        </nav>
                    </div>
                )}

                {/* Bottom bar */}
                <div className="border-t border-white/10">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-4 text-xs text-brand-200">
                        <span>© {new Date().getFullYear()} FirstMaket. All rights reserved.</span>
                        <span>
                            FirstMaket is not a loan app, bank, or BNPL service. No cash withdrawal.
                        </span>
                        <span>Secure payments by Paystack · Verified vendors · FirstMaket delivery</span>
                    </div>
                </div>
            </footer>

        </div>
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

function CartBadge({ count }: { count: number }) {
    if (!count) return null;

    return (
        <span className="animate-popIn absolute -right-2 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-yellow px-1 text-[10px] font-bold text-brand-900">
            {count > 99 ? '99+' : count}
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
