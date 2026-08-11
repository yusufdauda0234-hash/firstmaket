import PublicLayout from '@/Layouts/PublicLayout';
import { PageProps } from '@/Types';
import { cn } from '@/Utils/cn';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Award,
    BarChart3,
    ChevronRight,
    Clock,
    Heart,
    LayoutDashboard,
    LifeBuoy,
    LogOut,
    MapPin,
    MessageSquareWarning,
    MoreHorizontal,
    Package,
    PiggyBank,
    RotateCcw,
    Settings,
    ShoppingBag,
    Share2,
    Sparkles,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import { ComponentType, PropsWithChildren, ReactNode, useEffect, useState } from 'react';

interface AccountNavItem {
    label: string;
    icon: ComponentType<{ className?: string }>;
    href?: string;
    /** Marks it active when the current path matches this prefix (or exact). */
    match?: (path: string) => boolean;
    soon?: boolean;
}

interface AccountNavGroup {
    heading: string;
    items: AccountNavItem[];
}

/**
 * Signed-in customer shell — a synthesis of the Jumia / Temu / AliExpress
 * account patterns: the full storefront header and footer stay in place, with
 * a breadcrumb, a sticky profile + grouped-nav sidebar card on the left and the
 * page content on the right. On mobile the nav collapses to a horizontal
 * scroller above the content.
 */
export default function AccountLayout({ title, children }: PropsWithChildren<{ title: ReactNode }>) {
    const {
        props: { auth },
        url,
    } = usePage<PageProps>();
    const path = url.split('?')[0];
    const [menuOpen, setMenuOpen] = useState(false);

    // Any navigation closes the sheet — including a back-button move, which
    // changes the url without unmounting this layout.
    useEffect(() => setMenuOpen(false), [url]);

    // The sheet scrolls its own list; letting the page behind scroll too is
    // the classic "I closed it and lost my place" bug.
    useEffect(() => {
        if (!menuOpen) return;

        const { overflow } = document.body.style;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = overflow;
        };
    }, [menuOpen]);

    const initials = (auth.user?.name ?? '')
        .split(/\s+/)
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    const groups: AccountNavGroup[] = [
        {
            heading: 'Overview',
            items: [
                {
                    label: 'Account overview',
                    icon: LayoutDashboard,
                    href: route('account.overview'),
                    match: (p) => p === '/account' || p === '/account/',
                },
            ],
        },
        {
            heading: 'Shopping',
            items: [
                {
                    label: 'My Cart',
                    icon: ShoppingBag,
                    href: route('cart.index'),
                    match: (p) => p.startsWith('/cart'),
                },
                {
                    label: 'My Savings',
                    icon: PiggyBank,
                    href: route('savings.index'),
                    match: (p) =>
                        (p.startsWith('/savings') && !p.startsWith('/savings/together')) || p.startsWith('/checkout'),
                },
                {
                    label: 'Saving together',
                    icon: Users,
                    href: route('savings.together.index'),
                    match: (p) => p.startsWith('/savings/together'),
                },
                {
                    label: 'Savings assistant',
                    icon: Sparkles,
                    href: route('assistant.index'),
                    match: (p) => p.startsWith('/account/assistant'),
                },
                {
                    label: 'My Orders',
                    icon: Package,
                    href: route('orders.index'),
                    match: (p) => p.startsWith('/orders'),
                },
                {
                    label: 'Returns',
                    icon: RotateCcw,
                    href: route('returns.index'),
                    match: (p) => p.startsWith('/account/returns'),
                },
                {
                    label: 'Rewards & badges',
                    icon: Award,
                    href: route('rewards.index'),
                    match: (p) => p.startsWith('/account/rewards'),
                },
                {
                    label: 'Referrals',
                    icon: Share2,
                    href: route('referrals.index'),
                    match: (p) => p.startsWith('/account/referrals'),
                },
                {
                    label: 'Affiliate program',
                    icon: BarChart3,
                    href: route('affiliates.index'),
                    match: (p) => p.startsWith('/account/affiliate'),
                },
                {
                    label: 'Saved Items',
                    icon: Heart,
                    href: route('wishlist.index'),
                    match: (p) => p.startsWith('/account/wishlist'),
                },
                { label: 'Recently Viewed', icon: Clock, soon: true },
            ],
        },
        // No "Payments" group: it held a second "My Savings" pointing at the
        // same page as the one under Shopping — one destination listed twice,
        // under a wallet icon this marketplace does not have.
        {
            heading: 'Account',
            items: [
                {
                    label: 'Profile',
                    icon: UserRound,
                    href: route('account.profile'),
                    match: (p) => p.startsWith('/account/profile'),
                },
                {
                    label: 'Notifications',
                    icon: Bell,
                    href: route('notifications.index'),
                    match: (p) => p.startsWith('/notifications'),
                },
                {
                    label: 'Account settings',
                    icon: Settings,
                    href: route('account.settings'),
                    match: (p) => p.startsWith('/settings'),
                },
                { label: 'Addresses', icon: MapPin, soon: true },
            ],
        },
        {
            heading: 'Help',
            items: [
                {
                    label: 'Support Center',
                    icon: LifeBuoy,
                    href: route('support.index'),
                    match: (p) => p === '/support' || p.startsWith('/support/tickets'),
                },
                {
                    label: 'Make a complaint',
                    icon: MessageSquareWarning,
                    href: route('support.complaints.create'),
                    match: (p) => p.startsWith('/support/complaints'),
                },
            ],
        },
    ];

    /*
     * The four destinations that earn a permanent thumb-reachable slot, plus
     * a More tab for the rest.
     *
     * This replaced a horizontal scroller of ten identical pills: on a phone
     * it hid most of itself off-screen, gave no sense of where you were, and
     * put the whole account behind a sideways swipe. A fixed bar is the
     * pattern every shopping app converges on because the common destinations
     * stay one tap away from anywhere.
     */
    const bottomTabs: AccountNavItem[] = [
        {
            label: 'Account',
            icon: LayoutDashboard,
            href: route('account.overview'),
            match: (p) => p === '/account' || p === '/account/',
        },
        {
            label: 'Orders',
            icon: Package,
            href: route('orders.index'),
            match: (p) => p.startsWith('/orders'),
        },
        {
            label: 'Plans',
            icon: PiggyBank,
            href: route('savings.index'),
            match: (p) => p.startsWith('/savings') || p.startsWith('/checkout'),
        },
        {
            label: 'Saved',
            icon: Heart,
            href: route('wishlist.index'),
            match: (p) => p.startsWith('/account/wishlist'),
        },
    ];

    // Anything not on the bar is reachable from the sheet, so "More" is lit
    // whenever the current page is one of those.
    const onMoreSection = !bottomTabs.some((tab) => tab.match?.(path));

    return (
        <PublicLayout>
            {/* Bottom padding clears the fixed mobile bar, which would
                otherwise sit on top of the last card on every page. */}
            <div className="mx-auto w-full max-w-7xl px-4 pb-28 pt-6 lg:pb-6">
                {/* Breadcrumb */}
                <nav className="mb-4 flex items-center gap-1.5 text-sm text-gray-400" aria-label="Breadcrumb">
                    <Link href={route('home')} className="hover:text-brand-600">
                        Home
                    </Link>
                    <ChevronRight className="h-3.5 w-3.5" />
                    <Link href={route('account.overview')} className="hover:text-brand-600">
                        My Account
                    </Link>
                    <ChevronRight className="h-3.5 w-3.5" />
                    <span className="font-medium text-gray-700">{title}</span>
                </nav>

                <div className="grid gap-6 lg:grid-cols-[264px_1fr]">
                    {/* Sidebar (desktop) */}
                    <aside className="hidden lg:sticky lg:top-24 lg:block lg:self-start">
                        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                            {/* Profile block */}
                            <div className="flex items-center gap-3 border-b border-gray-100 bg-gradient-to-br from-brand-50 to-white px-4 py-4">
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-600 to-brand-900 text-sm font-extrabold text-white ring-2 ring-white">
                                    {initials || '?'}
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-bold text-gray-900">
                                        {auth.user?.name}
                                    </span>
                                    <span className="block truncate text-xs text-gray-400">
                                        {auth.user?.email ?? 'Welcome back'}
                                    </span>
                                </span>
                            </div>

                            {/* Grouped nav */}
                            <nav className="p-2">
                                {groups.map((group) => (
                                    <div key={group.heading} className="mb-1 last:mb-0">
                                        <p className="px-3 pb-1 pt-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">
                                            {group.heading}
                                        </p>
                                        {group.items.map((item) => {
                                            const active = item.match ? item.match(path) : false;
                                            if (item.soon) {
                                                return (
                                                    <span
                                                        key={item.label}
                                                        className="flex cursor-default items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-gray-400"
                                                        title="Coming soon"
                                                    >
                                                        <item.icon className="h-[18px] w-[18px] shrink-0" />
                                                        <span className="flex-1">{item.label}</span>
                                                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-400">
                                                            Soon
                                                        </span>
                                                    </span>
                                                );
                                            }
                                            return (
                                                <Link
                                                    key={item.label}
                                                    href={item.href!}
                                                    /* Fetch on hover, and on
                                                       pointer-down for touch
                                                       where there is no hover
                                                       to fetch on. By the time
                                                       the click lands the page
                                                       is usually already here. */
                                                    prefetch={['hover', 'click']}
                                                    cacheFor="30s"
                                                    className={cn(
                                                        'relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                                                        active
                                                            ? 'bg-brand-50 text-brand-700 before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-brand-600'
                                                            : 'text-gray-600 hover:bg-gray-50 hover:text-brand-700',
                                                    )}
                                                >
                                                    <item.icon
                                                        className={cn(
                                                            'h-[18px] w-[18px] shrink-0',
                                                            active ? 'text-brand-600' : 'text-gray-400',
                                                        )}
                                                    />
                                                    <span className="truncate">{item.label}</span>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                ))}
                            </nav>

                            {/* Logout */}
                            <div className="border-t border-gray-100 p-2">
                                <button
                                    type="button"
                                    onClick={() => router.post(route('logout'))}
                                    className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-gray-500 transition-colors hover:bg-red-50 hover:text-red-600"
                                >
                                    <LogOut className="h-[18px] w-[18px] shrink-0" />
                                    Log out
                                </button>
                            </div>
                        </div>
                    </aside>

                    {/* Page content */}
                    <div className="min-w-0">{children}</div>
                </div>
            </div>

            {/* ── Mobile bottom navigation ── */}
            <nav
                aria-label="Account"
                className="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 backdrop-blur-sm pb-[env(safe-area-inset-bottom)] lg:hidden"
            >
                <div className="mx-auto flex max-w-lg items-stretch">
                    {bottomTabs.map((tab) => {
                        const active = tab.match ? tab.match(path) : false;

                        return (
                            <Link
                                key={tab.label}
                                href={tab.href!}
                                prefetch={['hover', 'click']}
                                cacheFor="30s"
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex flex-1 flex-col items-center gap-1 px-1 py-2.5 text-[11px] font-semibold transition-colors',
                                    active ? 'text-brand-600' : 'text-gray-500',
                                )}
                            >
                                {/* The pill behind the icon carries the active
                                    state — colour alone is easy to miss at
                                    this size, and fails for colour blindness. */}
                                <span
                                    className={cn(
                                        'flex h-7 w-12 items-center justify-center rounded-full transition-colors',
                                        active && 'bg-brand-50',
                                    )}
                                >
                                    <tab.icon className="h-5 w-5" />
                                </span>
                                {tab.label}
                            </Link>
                        );
                    })}

                    <button
                        type="button"
                        onClick={() => setMenuOpen(true)}
                        aria-expanded={menuOpen}
                        aria-haspopup="dialog"
                        className={cn(
                            'flex flex-1 flex-col items-center gap-1 px-1 py-2.5 text-[11px] font-semibold transition-colors',
                            onMoreSection ? 'text-brand-600' : 'text-gray-500',
                        )}
                    >
                        <span
                            className={cn(
                                'flex h-7 w-12 items-center justify-center rounded-full transition-colors',
                                onMoreSection && 'bg-brand-50',
                            )}
                        >
                            <MoreHorizontal className="h-5 w-5" />
                        </span>
                        More
                    </button>
                </div>
            </nav>

            {/* ── "More" sheet: everything not on the bar ── */}
            {menuOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div
                        className="absolute inset-0 bg-gray-900/40 backdrop-blur-[2px]"
                        onClick={() => setMenuOpen(false)}
                        aria-hidden="true"
                    />

                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-label="Account menu"
                        className="absolute inset-x-0 bottom-0 flex max-h-[82vh] flex-col rounded-t-3xl bg-white shadow-2xl"
                    >
                        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                            <span className="flex min-w-0 items-center gap-3">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-600 to-brand-900 text-xs font-extrabold text-white">
                                    {initials || '?'}
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-bold text-gray-900">
                                        {auth.user?.name}
                                    </span>
                                    <span className="block truncate text-xs text-gray-400">
                                        {auth.user?.email ?? 'Welcome back'}
                                    </span>
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => setMenuOpen(false)}
                                aria-label="Close menu"
                                className="-mr-1 shrink-0 rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="overflow-y-auto overscroll-contain px-3 py-2 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                            {groups.map((group) => (
                                <div key={group.heading} className="mb-1 last:mb-0">
                                    <p className="px-3 pb-1 pt-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">
                                        {group.heading}
                                    </p>
                                    {group.items.map((item) => {
                                        const active = item.match ? item.match(path) : false;

                                        if (item.soon) {
                                            return (
                                                <span
                                                    key={item.label}
                                                    className="flex cursor-default items-center gap-3 rounded-xl px-3 py-3 text-sm text-gray-400"
                                                >
                                                    <item.icon className="h-[18px] w-[18px] shrink-0" />
                                                    <span className="flex-1">{item.label}</span>
                                                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-400">
                                                        Soon
                                                    </span>
                                                </span>
                                            );
                                        }

                                        return (
                                            <Link
                                                key={item.label}
                                                href={item.href!}
                                                prefetch={['hover', 'click']}
                                                cacheFor="30s"
                                                className={cn(
                                                    'flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-colors',
                                                    active
                                                        ? 'bg-brand-50 text-brand-700'
                                                        : 'text-gray-600 active:bg-gray-50',
                                                )}
                                            >
                                                <item.icon
                                                    className={cn(
                                                        'h-[18px] w-[18px] shrink-0',
                                                        active ? 'text-brand-600' : 'text-gray-400',
                                                    )}
                                                />
                                                <span className="truncate">{item.label}</span>
                                            </Link>
                                        );
                                    })}
                                </div>
                            ))}

                            <div className="mt-2 border-t border-gray-100 pt-2">
                                <button
                                    type="button"
                                    onClick={() => router.post(route('logout'))}
                                    className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium text-gray-500 transition-colors active:bg-red-50 active:text-red-600"
                                >
                                    <LogOut className="h-[18px] w-[18px] shrink-0" />
                                    Log out
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </PublicLayout>
    );
}
