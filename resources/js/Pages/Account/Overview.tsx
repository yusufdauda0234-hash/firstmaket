import { Badge } from '@/Components/ui/Badge';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Clock,
    Heart,
    LifeBuoy,
    Package,
    PackageCheck,
    Pencil,
    PiggyBank,
    ReceiptText,
    Star,
    Truck,
} from 'lucide-react';
import { ComponentType } from 'react';

interface Props {
    account: {
        name: string;
        email: string | null;
        emailVerified: boolean;
        phone: string | null;
        phoneVerified: boolean;
        memberSince: string | null;
    };
    planCreditKobo: number;
    activePlanCount: number;
    orderCounts: {
        saving: number;
        processing: number;
        shipped: number;
        toConfirm: number;
    };
    [key: string]: unknown;
}

/** A single order-status tile in the tracker row (AliExpress "My Orders" pattern). */
function OrderStatusTile({
    label,
    icon: Icon,
    count,
    href,
}: {
    label: string;
    icon: ComponentType<{ className?: string }>;
    count: number;
    href: string;
}) {
    return (
        <Link
            href={href}
            className="group relative flex flex-col items-center gap-1.5 rounded-xl px-2 py-1 text-center transition hover:bg-brand-50/60"
        >
            <span className="relative">
                <Icon className="h-7 w-7 text-gray-400 transition group-hover:text-brand-600" />
                {count > 0 && (
                    <span className="absolute -right-2 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">
                        {count}
                    </span>
                )}
            </span>
            <span className="text-xs font-medium leading-tight text-gray-600 group-hover:text-brand-700">
                {label}
            </span>
        </Link>
    );
}

/** A quick-action shortcut tile (AliExpress icon-row + Jumia shortcut grid). */
function ActionTile({
    label,
    icon: Icon,
    href,
    accent,
}: {
    label: string;
    icon: ComponentType<{ className?: string }>;
    href: string | null;
    accent: string;
}) {
    const inner = (
        <>
            <span className={cn('flex h-11 w-11 items-center justify-center rounded-full', accent)}>
                <Icon className="h-5 w-5" />
            </span>
            <span className="text-xs font-semibold leading-tight text-gray-700">{label}</span>
        </>
    );

    if (!href) {
        return (
            <span
                title="Coming soon"
                className="relative flex cursor-default flex-col items-center gap-2 rounded-2xl border border-gray-200 bg-white px-3 py-5 text-center opacity-60 shadow-sm"
            >
                {inner}
                <span className="absolute right-2 top-2 rounded-full bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-gray-400">
                    Soon
                </span>
            </span>
        );
    }

    return (
        <Link
            href={href}
            className="flex flex-col items-center gap-2 rounded-2xl border border-gray-200 bg-white px-3 py-5 text-center shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md"
        >
            {inner}
        </Link>
    );
}

export default function AccountOverview() {
    const { account, planCreditKobo, activePlanCount, orderCounts } = usePage<Props>().props;
    const firstName = account.name.split(' ')[0];
    const initials = account.name
        .split(/\s+/)
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    return (
        <AccountLayout title="Account overview">
            <Head title="My Account" />

            {/* ── Profile hero (Temu / AliExpress) ── */}
            <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-900 p-6 text-white shadow-lg sm:p-7">
                <span
                    className="pointer-events-none absolute -right-8 -top-10 select-none text-[10rem] leading-none opacity-10"
                    aria-hidden="true"
                >
                    ₦
                </span>
                <div className="relative z-[1] flex flex-wrap items-center gap-4">
                    <span className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-white/15 text-xl font-extrabold text-white ring-2 ring-white/40 backdrop-blur">
                        {initials || '?'}
                    </span>
                    {/* Claims the rest of the first line on a phone (the avatar
                        plus its gap is exactly 5rem), which pushes Edit profile
                        onto its own row. Left as plain `flex-1`, the text column
                        was allowed to shrink to nothing so the button could
                        stay alongside — crushing "Welcome back" onto two lines
                        and wrapping the member-since pill into a blob. */}
                    <div className="min-w-0 flex-1 basis-[calc(100%-5rem)] sm:basis-auto">
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-100">
                            Welcome back
                        </p>
                        <h1 className="mt-0.5 truncate text-2xl font-extrabold tracking-tight">{firstName}</h1>
                        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-brand-100">
                            {account.memberSince && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-white/10 px-2.5 py-1 font-medium">
                                    <Clock className="h-3.5 w-3.5 shrink-0" />
                                    <span className="whitespace-nowrap">
                                        Member since {account.memberSince}
                                    </span>
                                </span>
                            )}
                        </div>
                    </div>
                    <Link
                        href={route('account.profile')}
                        className="inline-flex w-full items-center justify-center gap-1.5 rounded-full bg-white/15 px-4 py-2 text-sm font-bold text-white backdrop-blur transition hover:bg-white/25 sm:w-auto"
                    >
                        <Pencil className="h-3.5 w-3.5" /> Edit profile
                    </Link>
                </div>
            </section>

            {/* ── Order status tracker (AliExpress "My Orders") ── */}
            <section className="mt-4 rounded-2xl border border-gray-200 bg-white shadow-sm">
                <header className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h2 className="text-sm font-bold text-gray-900">My Orders</h2>
                    <Link
                        href={route('orders.index')}
                        className="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline"
                    >
                        View all <ArrowRight className="h-3.5 w-3.5" />
                    </Link>
                </header>
                <div className="grid grid-cols-4 gap-2 px-3 py-5">
                    <OrderStatusTile
                        label="Saving"
                        icon={PiggyBank}
                        count={orderCounts.saving}
                        href={route('savings.index')}
                    />
                    <OrderStatusTile
                        label="Processing"
                        icon={Package}
                        count={orderCounts.processing}
                        href={route('orders.index')}
                    />
                    <OrderStatusTile
                        label="Shipped"
                        icon={Truck}
                        count={orderCounts.shipped}
                        href={route('orders.index')}
                    />
                    <OrderStatusTile
                        label="To confirm"
                        icon={Star}
                        count={orderCounts.toConfirm}
                        href={route('orders.index')}
                    />
                </div>
            </section>

            {/* ── Quick actions (AliExpress icon row + Jumia shortcuts) ── */}
            <div className="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-6">
                {/* One tile per destination. This row previously held "My
                    Savings", "Transactions" and "Savings" — three tiles, one
                    page, and a wallet icon for a marketplace with no wallet. */}
                <ActionTile
                    label="Pay Small Small"
                    icon={PiggyBank}
                    href={route('savings.index')}
                    accent="bg-brand-50 text-brand-600"
                />
                <ActionTile
                    label="Notifications"
                    icon={ReceiptText}
                    href={route('notifications.index')}
                    accent="bg-indigo-50 text-indigo-600"
                />
                <ActionTile
                    label="Support"
                    icon={LifeBuoy}
                    href={route('support.index')}
                    accent="bg-amber-50 text-amber-600"
                />
                <ActionTile
                    label="Saved Items"
                    icon={Heart}
                    href={route('wishlist.index')}
                    accent="bg-rose-50 text-rose-600"
                />
                <ActionTile
                    label="My Orders"
                    icon={PackageCheck}
                    href={route('orders.index')}
                    accent="bg-sky-50 text-sky-600"
                />
            </div>

            {/* ── Detail cards (Jumia account-overview grid) ── */}
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                {/* Account details */}
                <section className="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <header className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                        <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Account details</h2>
                        <Link
                            href={route('account.profile')}
                            className="text-xs font-semibold text-brand-600 hover:underline"
                        >
                            Edit
                        </Link>
                    </header>
                    <div className="space-y-3 px-5 py-4">
                        <p className="text-base font-bold text-gray-900">{account.name}</p>
                        {/* Wrapping, not truncating: an address long enough to
                            crowd the badge is exactly the one worth reading in
                            full, and the badge must never be pushed off the
                            card — it is the reason to look at this line. */}
                        <div>
                            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600">
                                <span className="min-w-0 break-all">
                                    {account.email ?? 'No email added'}
                                </span>
                                {account.email && (
                                    <Badge
                                        tone={account.emailVerified ? 'success' : 'warning'}
                                        className="shrink-0"
                                    >
                                        {account.emailVerified ? 'verified' : 'unverified'}
                                    </Badge>
                                )}
                            </p>
                            <p className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600">
                                <span className="min-w-0 break-all">
                                    {account.phone ?? 'No phone added'}
                                </span>
                                {account.phone && (
                                    <Badge
                                        tone={account.phoneVerified ? 'success' : 'warning'}
                                        className="shrink-0"
                                    >
                                        {account.phoneVerified ? 'verified' : 'unverified'}
                                    </Badge>
                                )}
                            </p>
                        </div>
                        {account.memberSince && (
                            <p className="text-xs text-gray-400">Member since {account.memberSince}</p>
                        )}
                    </div>
                </section>

                {/* Pay Small Small.
                    Deliberately not a balance card. This used to show
                    savings.balance_kobo — a column left behind by the retired
                    wallet that is pinned at zero, so it read "Available
                    balance ₦0" forever and implied a wallet FirstMaket does
                    not have. What is real is how many plans are running, and
                    credit from a cancelled plan, which is shown only when
                    there is some. */}
                <section className="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <header className="border-b border-gray-100 px-5 py-3">
                        <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Pay Small Small</h2>
                    </header>
                    <div className="px-5 py-4">
                        <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-900 p-5 text-white">
                            <PiggyBank
                                className="pointer-events-none absolute -right-3 -top-3 h-20 w-20 opacity-10"
                                aria-hidden="true"
                            />
                            <p className="relative z-[1] text-xs font-semibold uppercase tracking-wide text-brand-100">
                                {activePlanCount === 1 ? 'Active plan' : 'Active plans'}
                            </p>
                            <p className="relative z-[1] mt-1 text-3xl font-extrabold tracking-tight">
                                {activePlanCount}
                            </p>
                            <p className="relative z-[1] mt-2 text-xs leading-relaxed text-brand-100">
                                {activePlanCount === 0
                                    ? 'Lock a price today and pay it off bit by bit.'
                                    : 'Money lives inside the plan it was paid into.'}
                            </p>
                        </div>

                        {planCreditKobo > 0 && (
                            <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700">
                                {formatNairaFromKobo(planCreditKobo)} credit from a cancelled plan — it goes
                                onto your next plan automatically.
                            </p>
                        )}

                        <div className="mt-3 flex items-center gap-3">
                            <Link
                                href={route('savings.index')}
                                className="rounded-full bg-brand-yellow px-4 py-2 text-xs font-bold text-brand-900 transition hover:bg-yellow-300 active:scale-95"
                            >
                                My plans
                            </Link>
                            <Link
                                href={route('catalog.index')}
                                className="text-sm font-semibold text-brand-600 hover:underline"
                            >
                                Start a new plan
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </AccountLayout>
    );
}
