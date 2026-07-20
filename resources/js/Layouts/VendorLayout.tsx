import { cn } from '@/Utils/cn';
import { PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    ChevronLeft,
    ClipboardList,
    ExternalLink,
    LayoutDashboard,
    LogOut,
    Menu,
    Package,
    ShieldCheck,
    X,
} from 'lucide-react';
import { ComponentType, PropsWithChildren, useEffect, useState } from 'react';

interface NavItem {
    label: string;
    href: string;
    icon: ComponentType<{ className?: string }>;
    active: boolean;
}

const COLLAPSE_KEY = 'fm.vendor-sidebar-collapsed';

/**
 * Vendor Center shell (vendors subdomain): a responsive sidebar — collapsible
 * on desktop, slide-over drawer on mobile — in the light marketplace look.
 * Replaces the old top-nav so the portal reads like a real seller workspace.
 */
export default function VendorLayout({ children }: PropsWithChildren) {
    const {
        props: { auth, mainSiteUrl, flash },
        url,
    } = usePage<PageProps>();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);

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
            return next;
        });

    const path = url.split('?')[0];
    const nav: NavItem[] = [
        {
            label: 'Dashboard',
            href: route('vendor.dashboard'),
            icon: LayoutDashboard,
            active: path === '/dashboard' || path === '/',
        },
        {
            label: 'My Products',
            href: route('vendor.products.index'),
            icon: Package,
            active: path.startsWith('/products'),
        },
        {
            label: 'Orders',
            href: route('vendor.orders.index'),
            icon: ClipboardList,
            active: path.startsWith('/orders'),
        },
        {
            label: 'Earnings',
            href: route('vendor.earnings'),
            icon: Banknote,
            active: path.startsWith('/earnings'),
        },
    ];

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
                href={route('vendor.dashboard')}
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
                                Vendor
                            </span>
                        </span>
                        <span className="mt-1 block text-[11px] font-medium text-gray-400">Seller workspace</span>
                    </span>
                )}
            </Link>

            {/* Nav */}
            <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                {nav.map((item) => (
                    <Link
                        key={item.label}
                        href={item.href}
                        title={collapsed && !mobile ? item.label : undefined}
                        className={cn(
                            'group flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all duration-200',
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

                {(!collapsed || mobile) && (
                    <a
                        href={mainSiteUrl}
                        className="mt-2 flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-gray-500 transition hover:bg-gray-50 hover:text-brand-700"
                    >
                        <ExternalLink className="h-[18px] w-[18px] shrink-0 text-gray-400" />
                        <span>Back to marketplace</span>
                    </a>
                )}
            </nav>

            {/* Footer: user + logout + collapse */}
            <div className="space-y-1 border-t border-gray-200 p-3">
                {(!collapsed || mobile) && (
                    <div className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-extrabold text-white">
                            {initials || '?'}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-semibold text-gray-900">{auth.user?.name}</span>
                            <span className="block truncate text-[11px] text-gray-500">Vendor account</span>
                        </span>
                        <button
                            type="button"
                            onClick={() => router.post(route('vendor.logout'))}
                            aria-label="Log out"
                            title="Log out"
                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-500 transition hover:bg-red-50 hover:text-red-600 active:scale-90"
                        >
                            <LogOut className="h-4 w-4" />
                        </button>
                    </div>
                )}
                {collapsed && !mobile && (
                    <button
                        type="button"
                        onClick={() => router.post(route('vendor.logout'))}
                        aria-label="Log out"
                        title="Log out"
                        className="flex w-full items-center justify-center rounded-xl py-2.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600 active:scale-90"
                    >
                        <LogOut className="h-[18px] w-[18px]" />
                    </button>
                )}

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
    const mainMargin = collapsed ? 'lg:ml-[76px]' : 'lg:ml-64';

    return (
        // Flex column so the footer sits at the bottom of the viewport even
        // when the page content is short, instead of floating mid-screen.
        <div className="flex min-h-screen flex-col bg-gray-50 text-gray-900">
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
                <Link href={route('vendor.dashboard')} className="flex items-center gap-2">
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-600 to-brand-900">
                        <img src="/images/brand/logo-mark-transparent.png" alt="FirstMarket" className="h-5 w-5 object-contain" />
                    </span>
                    <span className="rounded-full bg-brand-yellow px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand-900">
                        Vendor
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

            {flash.success && (
                <div className={cn('mx-auto mt-4 w-full max-w-6xl px-4 sm:px-6 lg:px-8', mainMargin)}>
                    <p className="rounded-xl bg-green-50 px-4 py-2.5 text-sm text-green-700" role="status">
                        {flash.success}
                    </p>
                </div>
            )}
            {flash.error && (
                <div className={cn('mx-auto mt-4 w-full max-w-6xl px-4 sm:px-6 lg:px-8', mainMargin)}>
                    <p className="rounded-xl bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                        {flash.error}
                    </p>
                </div>
            )}

            <main className={cn('flex-1 px-4 py-6 transition-[margin] duration-200 sm:px-6 lg:px-8 lg:py-8', mainMargin)}>
                <div className="mx-auto max-w-6xl">{children}</div>
            </main>

            <footer className={cn('border-t border-gray-200 bg-white', mainMargin)}>
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-4 text-xs text-gray-400 sm:px-6 lg:px-8">
                    <span className="flex items-center gap-1.5">
                        <ShieldCheck className="h-3.5 w-3.5 text-brand-500" /> Listings are reviewed before they go live.
                    </span>
                    <span>Zero listing fees today · Instant Paystack payouts · FirstMarket delivers</span>
                </div>
            </footer>
        </div>
    );
}
