import PopoverCaret from '@/Components/ui/PopoverCaret';
import { useHoverPopover } from '@/Hooks/use-popover';
import { AuthenticatedUser } from '@/Types';
import { Link, router } from '@inertiajs/react';

/**
 * Header account menu (Jumia/AliExpress pattern): hover or click to open.
 * Guests get a Sign in button plus quick links that open the auth modal;
 * signed-in users get their account shortcuts and logout.
 */
export default function AccountDropdown({
    user,
    onOpenAuth,
}: {
    user: AuthenticatedUser | null;
    onOpenAuth: () => void;
}) {
    const { open, setOpen, ref, hoverProps } = useHoverPopover<HTMLDivElement>();

    const guestLinks = [
        { label: 'My Account', icon: <UserSmallIcon /> },
        { label: 'My Orders', icon: <OrdersIcon /> },
        { label: 'Saved Items', icon: <HeartIcon /> },
    ];

    return (
        <div ref={ref} className="relative" {...hoverProps}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex items-center gap-1.5 text-sm font-medium text-gray-700 hover:text-brand-600"
                aria-expanded={open}
                aria-haspopup="menu"
            >
                <UserIcon />
                <span className="hidden sm:inline">{user ? `Hi, ${user.name.split(' ')[0]}` : 'Account'}</span>
                <svg
                    className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`}
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={2}
                    stroke="currentColor"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {open && (
                <div
                    role="menu"
                    className="fixed left-2 right-2 top-14 z-50 max-h-[calc(100dvh-4.5rem)] min-w-0 overflow-y-auto overflow-x-hidden rounded-2xl border border-gray-200 bg-white p-2 shadow-xl shadow-slate-900/10 sm:absolute sm:left-auto sm:right-0 sm:top-full sm:mt-3 sm:max-h-[calc(100dvh-5rem)] sm:w-80 sm:p-4"
                >
                    <PopoverCaret className="right-9" />
                    {user ? (
                        <>
                            {/* ── Greeting ── */}
                            <p className="px-2 pb-2 pt-1 text-sm text-gray-500">
                                Hi, <span className="font-semibold text-gray-800">{user.name.split(' ')[0]}</span>
                            </p>
                            <Link
                                href={route('dashboard')}
                                className="block rounded-full bg-brand-600 py-2.5 text-center text-sm font-bold text-white transition-colors hover:bg-brand-700"
                            >
                                Go to my dashboard
                            </Link>

                            {/* ── Account links — every page a customer owns ── */}
                            <ul className="mt-2 space-y-0.5 text-sm text-gray-700">
                                {[
                                    { label: 'My Account', href: route('account.overview'), icon: <UserSmallIcon /> },
                                    { label: 'My Orders', href: route('orders.index'), icon: <OrdersIcon /> },
                                    { label: 'My Savings', href: route('savings.index'), icon: <WalletIcon /> },
                                    // No "Identity verification" entry: the dedicated page went away
                                    // when BVN/NIN checks were dropped (commit bb15765) and phone
                                    // verification became the dashboard modal (VerifyPhoneModal).
                                    { label: 'Support Center', href: route('support.index'), icon: <UserSmallIcon /> },
                                ].map((item) => (
                                    <li key={item.label}>
                                        <Link
                                            href={item.href}
                                            className="flex items-center gap-2.5 rounded-lg px-2.5 py-2.5 transition-colors hover:bg-slate-50 hover:text-brand-600"
                                        >
                                            {item.icon} {item.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>

                            {/* ── Footer: Logout ── */}
                            <div className="mt-3 border-t border-gray-100 pt-3">
                                <button
                                    type="button"
                                    onClick={() => router.post(route('logout'))}
                                    className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-red-50 hover:text-red-600"
                                >
                                    <LogoutIcon /> Log out
                                </button>
                            </div>
                        </>
                    ) : (
                        <>
                            {/* ── Sign in / Register — own shadowed card at top ── */}
                            <div className="space-y-1.5 rounded-xl border border-gray-100 bg-slate-50 p-2.5 shadow-md sm:p-3">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setOpen(false);
                                        onOpenAuth();
                                    }}
                                    className="w-full rounded-full bg-brand-600 py-2.5 text-sm font-bold text-white transition-colors hover:bg-brand-700"
                                >
                                    Sign in
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setOpen(false);
                                        onOpenAuth();
                                    }}
                                    className="w-full rounded-full border border-gray-200 bg-white py-2 text-center text-sm font-medium text-gray-700 transition-colors hover:border-gray-300 hover:bg-slate-100"
                                >
                                    Register
                                </button>
                            </div>

                            {/* ── Quick links below ── */}
                            <ul className="mt-2 space-y-0.5 text-sm text-gray-700">
                                {guestLinks.map((item) => (
                                    <li key={item.label}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setOpen(false);
                                                onOpenAuth();
                                            }}
                                            className="flex w-full min-w-0 items-center gap-2.5 rounded-lg px-2 py-2.5 text-left transition-colors hover:bg-slate-50 hover:text-brand-600 sm:px-2.5"
                                        >
                                            {item.icon} {item.label}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

function UserIcon() {
    return (
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.1a7.5 7.5 0 0 1 15 0A17.9 17.9 0 0 1 12 21.75c-2.68 0-5.22-.58-7.5-1.64Z"
            />
        </svg>
    );
}

function UserSmallIcon() {
    return (
        <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.1a7.5 7.5 0 0 1 15 0A17.9 17.9 0 0 1 12 21.75c-2.68 0-5.22-.58-7.5-1.64Z"
            />
        </svg>
    );
}

function WalletIcon() {
    return (
        <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3"
            />
        </svg>
    );
}

function OrdersIcon() {
    return (
        <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M9 12h6m-6 4h6M9 8h6M5.25 3.75h13.5v16.5l-2.25-1.5-2.25 1.5-2.25-1.5-2.25 1.5-2.25-1.5-2.25 1.5V3.75Z"
            />
        </svg>
    );
}

function HeartIcon() {
    return (
        <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M21 8.61c0 5.14-6.98 9.75-9 10.61-2.02-.86-9-5.47-9-10.61a4.86 4.86 0 0 1 9-2.56A4.86 4.86 0 0 1 21 8.6Z"
            />
        </svg>
    );
}

function LogoutIcon() {
    return (
        <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"
            />
        </svg>
    );
}
