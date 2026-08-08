import PublicLayout from '@/Layouts/PublicLayout';
import { PageProps } from '@/Types';
import { cn } from '@/Utils/cn';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    ChevronRight,
    Clock,
    Heart,
    LayoutDashboard,
    LifeBuoy,
    LogOut,
    MapPin,
    Package,
    PiggyBank,
    Settings,
    ShoppingBag,
} from 'lucide-react';
import { ComponentType, PropsWithChildren, ReactNode } from 'react';

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
                    match: (p) => p.startsWith('/savings') || p.startsWith('/checkout'),
                },
                {
                    label: 'My Orders',
                    icon: Package,
                    href: route('orders.index'),
                    match: (p) => p.startsWith('/orders'),
                },
                { label: 'Saved Items', icon: Heart, soon: true },
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
                    match: (p) => p.startsWith('/support'),
                },
            ],
        },
    ];

    // Flat list for the mobile horizontal scroller (links only — no "soon" clutter).
    const mobileItems = groups.flatMap((g) => g.items).filter((i) => i.href);

    return (
        <PublicLayout>
            <div className="mx-auto w-full max-w-7xl px-4 py-6">
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

                {/* Mobile nav scroller */}
                <div className="mb-4 flex gap-2 overflow-x-auto pb-1 lg:hidden [-ms-overflow-style:none] [scrollbar-width:none]">
                    {mobileItems.map((item) => {
                        const active = item.match ? item.match(path) : false;
                        return (
                            <Link
                                key={item.label}
                                href={item.href!}
                                className={cn(
                                    'flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full border px-3.5 py-2 text-sm font-medium transition-colors',
                                    active
                                        ? 'border-brand-200 bg-brand-50 text-brand-700'
                                        : 'border-gray-200 bg-white text-gray-600',
                                )}
                            >
                                <item.icon className="h-4 w-4" />
                                {item.label}
                            </Link>
                        );
                    })}
                </div>

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
        </PublicLayout>
    );
}
