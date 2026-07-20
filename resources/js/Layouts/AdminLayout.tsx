import { cn } from '@/Utils/cn';
import { PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    ChevronLeft,
    ClipboardList,
    LayoutDashboard,
    LifeBuoy,
    LogOut,
    Menu,
    PackageCheck,
    Percent,
    Scale,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    Store,
    Truck,
    X,
} from 'lucide-react';
import { ComponentType, PropsWithChildren, useEffect, useMemo, useState } from 'react';

interface NavItem {
    label: string;
    href: string;
    icon: ComponentType<{ className?: string }>;
    active: boolean;
    show: boolean;
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

const COLLAPSE_KEY = 'fm.admin-sidebar-collapsed';

/**
 * Staff portal shell (2026 marketplace style): collapsible grouped sidebar
 * with a filter search and hover tooltips when collapsed, a glass sticky
 * header, and a mobile slide-over drawer. Light-only to match the public site.
 */
export default function AdminLayout({ children }: PropsWithChildren) {
    const {
        props: { auth },
        url,
    } = usePage<PageProps>();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);
    const [search, setSearch] = useState('');

    useEffect(() => {
        try {
            setCollapsed(localStorage.getItem(COLLAPSE_KEY) === '1');
        } catch {
            /* ignore */
        }
    }, []);

    useEffect(() => setDrawerOpen(false), [url]);

    const toggleCollapse = () =>
        setCollapsed((prev) => {
            const next = !prev;
            try {
                localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
            } catch {
                /* ignore */
            }
            if (next) setSearch('');
            return next;
        });

    const isSuper = auth.user?.roles.includes('Super Administrator') ?? false;
    const can = (permission: string) => isSuper || (auth.user?.permissions.includes(permission) ?? false);
    const path = url.split('?')[0];

    const groups = useMemo<NavGroup[]>(
        () => [
            {
                label: 'Overview',
                items: [
                    {
                        label: 'Dashboard',
                        href: route('admin.dashboard'),
                        icon: LayoutDashboard,
                        active: path === '/',
                        show: true,
                    },
                ],
            },
            {
                label: 'Marketplace',
                items: [
                    {
                        label: 'Vendors',
                        href: route('admin.vendors.index'),
                        icon: Store,
                        active: path.startsWith('/vendors'),
                        show: can('vendors.view'),
                    },
                    {
                        label: 'Products',
                        href: route('admin.products.index'),
                        icon: PackageCheck,
                        active: path.startsWith('/products'),
                        show: can('products.approve'),
                    },
                    {
                        label: 'Orders',
                        href: route('admin.orders.index'),
                        icon: ClipboardList,
                        active: path.startsWith('/orders'),
                        show: can('orders.manage'),
                    },
                    {
                        label: 'Deliveries',
                        href: route('admin.deliveries.index'),
                        icon: Truck,
                        active: path.startsWith('/deliveries'),
                        show: can('delivery.update'),
                    },
                ],
            },
            {
                label: 'Customer care',
                items: [
                    {
                        label: 'Support',
                        href: route('admin.support.index'),
                        icon: LifeBuoy,
                        active: path.startsWith('/support'),
                        show: can('support.manage'),
                    },
                ],
            },
            {
                label: 'Finance',
                items: [
                    {
                        label: 'Reconciliation',
                        href: route('admin.reconciliation.index'),
                        icon: Scale,
                        active: path.startsWith('/reconciliation'),
                        show: can('wallet.reconcile'),
                    },
                    {
                        label: 'Vendor payouts',
                        href: route('admin.payouts.index'),
                        icon: Banknote,
                        active: path.startsWith('/payouts'),
                        show: can('vendor_payouts.approve'),
                    },
                ],
            },
            {
                label: 'System',
                items: [
                    {
                        label: 'Fee settings',
                        href: route('admin.settings.fees'),
                        icon: SlidersHorizontal,
                        active: path.startsWith('/settings/fees'),
                        show: can('vendor_fees.manage'),
                    },
                    {
                        label: 'Commissions',
                        href: route('admin.settings.commissions'),
                        icon: Percent,
                        active: path.startsWith('/settings/commissions'),
                        show: can('commissions.manage'),
                    },
                ],
            },
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [path, isSuper],
    );

    const term = search.trim().toLowerCase();
    const visibleGroups = groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => item.show && (!term || item.label.toLowerCase().includes(term))),
        }))
        .filter((group) => group.items.length > 0);

    const initials = (auth.user?.name ?? '')
        .split(/\s+/)
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    const sidebar = (mobile: boolean) => (
        <div className="flex h-full flex-col bg-white text-gray-900">
            {/* Logo lockup */}
            <Link
                href={route('admin.dashboard')}
                className={cn(
                    'flex h-[68px] shrink-0 items-center gap-3 border-b border-gray-200 px-5',
                    collapsed && !mobile && 'justify-center px-0',
                )}
            >
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600 to-brand-900 shadow-sm">
                    <img src="/images/brand/logo-mark-transparent.png" alt="FirstMarket" className="h-9 w-9 object-contain" />
                </span>
                {(!collapsed || mobile) && (
                    <span className="min-w-0">
                        <span className="flex items-center gap-2">
                            <span className="text-[15px] font-extrabold leading-none tracking-tight text-gray-900">
                                FirstMarket
                            </span>
                            <span className="rounded-md bg-brand-yellow px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-brand-900">
                                Staff
                            </span>
                        </span>
                        <span className="mt-1 block text-[11px] font-medium text-gray-400">Admin workspace</span>
                    </span>
                )}
            </Link>

            {/* Search (hidden when collapsed) */}
            {(!collapsed || mobile) && (
                <div className="px-3 pt-3">
                    <div className="flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 focus-within:border-brand-300 focus-within:bg-white focus-within:ring-2 focus-within:ring-brand-500/15">
                        <Search className="h-3.5 w-3.5 shrink-0 text-gray-400" />
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search menu…"
                            className="w-full bg-transparent text-sm text-gray-700 placeholder:text-gray-400 focus:outline-none"
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                className="text-gray-400 hover:text-gray-600"
                                aria-label="Clear search"
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Nav */}
            <nav className="flex-1 space-y-4 overflow-y-auto px-3 py-4">
                {visibleGroups.map((group) => (
                    <div key={group.label}>
                        {(!collapsed || mobile) && (
                            <p className="mb-1 px-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">
                                {group.label}
                            </p>
                        )}
                        <div className="space-y-1">
                            {group.items.map((item) => (
                                <Link
                                    key={item.label}
                                    href={item.href}
                                    title={collapsed && !mobile ? item.label : undefined}
                                    className={cn(
                                        'group relative flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all duration-200',
                                        collapsed && !mobile && 'justify-center px-0',
                                        item.active
                                            ? 'bg-brand-600 text-white shadow-sm shadow-brand-600/25'
                                            : 'text-gray-600 hover:translate-x-0.5 hover:bg-brand-50 hover:text-brand-700',
                                    )}
                                >
                                    <item.icon
                                        className={cn(
                                            'h-[18px] w-[18px] shrink-0 transition-colors',
                                            item.active ? 'text-brand-yellow' : 'text-gray-400 group-hover:text-brand-600',
                                        )}
                                    />
                                    {(!collapsed || mobile) && <span className="truncate">{item.label}</span>}
                                    {item.active && (!collapsed || mobile) && (
                                        <span className="ml-auto h-1.5 w-1.5 rounded-full bg-brand-yellow" aria-hidden="true" />
                                    )}
                                </Link>
                            ))}
                        </div>
                    </div>
                ))}
                {term && visibleGroups.length === 0 && (
                    <p className="px-3 py-6 text-center text-xs text-gray-400">No matching items</p>
                )}
            </nav>

            {/* Footer: audit hint + user + logout + collapse */}
            <div className="space-y-1 border-t border-gray-200 p-3">
                {(!collapsed || mobile) && (
                    <>
                        <p className="mb-2 flex items-center gap-2 px-1 text-[11px] text-gray-500">
                            <ShieldCheck className="h-3.5 w-3.5 shrink-0 text-brand-600" />
                            Actions here are audit-logged.
                        </p>
                        <div className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-3">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-extrabold text-white">
                                {initials || '?'}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-semibold text-gray-900">
                                    {auth.user?.name}
                                </span>
                                <span className="block truncate text-[11px] text-gray-500">
                                    {auth.user?.roles.join(', ')}
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => router.post(route('admin.logout'))}
                                aria-label="Log out"
                                title="Log out"
                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-500 transition hover:bg-red-50 hover:text-red-600 active:scale-90"
                            >
                                <LogOut className="h-4 w-4" />
                            </button>
                        </div>
                    </>
                )}
                {collapsed && !mobile && (
                    <button
                        type="button"
                        onClick={() => router.post(route('admin.logout'))}
                        aria-label="Log out"
                        title="Log out"
                        className="flex w-full items-center justify-center rounded-xl py-2.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600 active:scale-90"
                    >
                        <LogOut className="h-[18px] w-[18px]" />
                    </button>
                )}

                {/* Collapse toggle — desktop only */}
                {!mobile && (
                    <button
                        type="button"
                        onClick={toggleCollapse}
                        aria-label="Toggle sidebar"
                        className={cn(
                            'hidden w-full items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-medium text-gray-500 transition hover:bg-gray-100 lg:flex',
                            collapsed && 'justify-center px-0',
                        )}
                    >
                        <ChevronLeft className={cn('h-4 w-4 transition-transform duration-200', collapsed && 'rotate-180')} />
                        {!collapsed && <span>Collapse</span>}
                    </button>
                )}
            </div>
        </div>
    );

    const sidebarWidth = collapsed ? 'lg:w-[76px]' : 'lg:w-64';
    // Offset the fixed sidebar with a MARGIN, so the page's own symmetric
    // px padding still applies as an even gutter on both sides — otherwise
    // pl-64 leaves the content flush against the sidebar with no breathing room.
    const mainMargin = collapsed ? 'lg:ml-[76px]' : 'lg:ml-64';

    return (
        <div className="min-h-screen bg-gray-50 text-gray-900">
            {/* Desktop sidebar */}
            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-40 hidden border-r border-gray-200 transition-[width] duration-200 lg:block',
                    sidebarWidth,
                )}
            >
                {sidebar(false)}
            </aside>

            {/* Mobile top bar */}
            <header className="sticky top-0 z-40 flex items-center justify-between border-b border-gray-200 bg-white/80 px-4 py-3 backdrop-blur-md lg:hidden">
                <Link href={route('admin.dashboard')} className="flex items-center gap-2">
                    <img src="/images/brand/logo-mark-dark.png" alt="FirstMarket" className="h-8 w-auto" />
                    <span className="rounded-full bg-brand-yellow px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand-900">
                        Staff
                    </span>
                </Link>
                <button
                    type="button"
                    onClick={() => setDrawerOpen(true)}
                    aria-label="Open menu"
                    className="rounded-full border border-gray-200 p-2 text-gray-600 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 active:scale-90"
                >
                    <Menu className="h-5 w-5" />
                </button>
            </header>

            {/* Mobile drawer */}
            {drawerOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div
                        className="animate-fadeIn absolute inset-0 bg-slate-900/60 backdrop-blur-sm"
                        onClick={() => setDrawerOpen(false)}
                        aria-hidden="true"
                    />
                    <div className="animate-slideInRight absolute inset-y-0 left-0 w-72 shadow-2xl">
                        {sidebar(true)}
                        <button
                            type="button"
                            onClick={() => setDrawerOpen(false)}
                            aria-label="Close menu"
                            className="absolute right-3 top-4 rounded-full bg-gray-100 p-2 text-gray-500 transition hover:bg-gray-200 active:scale-90"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}

            <main className={cn('px-4 py-6 transition-[margin] duration-200 sm:px-6 lg:px-8 lg:py-8', mainMargin)}>
                <div className="mx-auto max-w-6xl">{children}</div>
            </main>
        </div>
    );
}
