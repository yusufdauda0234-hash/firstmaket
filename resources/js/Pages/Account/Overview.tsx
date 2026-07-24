import { Badge } from '@/Components/ui/Badge';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Clock,
    CreditCard,
    Heart,
    Package,
    PackageCheck,
    Pencil,
    PiggyBank,
    Plus,
    ReceiptText,
    Star,
    Truck,
    Wallet,
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
    walletBalanceKobo: number;
    orderCounts: {
        awaitingAddress: number;
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
            <span className="text-xs font-medium text-gray-600 group-hover:text-brand-700">{label}</span>
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
            <span className="text-xs font-semibold text-gray-700">{label}</span>
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
    const { account, walletBalanceKobo, orderCounts } = usePage<Props>().props;
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
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-100">
                            Welcome back
                        </p>
                        <h1 className="mt-0.5 truncate text-2xl font-extrabold tracking-tight">{firstName}</h1>
                        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-brand-100">
                            {account.memberSince && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-white/10 px-2.5 py-1 font-medium">
                                    <Clock className="h-3.5 w-3.5" />
                                    Member since {account.memberSince}
                                </span>
                            )}
                        </div>
                    </div>
                    <Link
                        href={route('account.settings')}
                        className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-4 py-2 text-sm font-bold text-white backdrop-blur transition hover:bg-white/25"
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
                        label="Add address"
                        icon={CreditCard}
                        count={orderCounts.awaitingAddress}
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
                <ActionTile
                    label="My Wallet"
                    icon={Wallet}
                    href={route('wallet.index')}
                    accent="bg-brand-50 text-brand-600"
                />
                <ActionTile
                    label="Transactions"
                    icon={ReceiptText}
                    href={route('wallet.transactions')}
                    accent="bg-indigo-50 text-indigo-600"
                />
                <ActionTile
                    label="Savings"
                    icon={PiggyBank}
                    href={route('savings.index')}
                    accent="bg-amber-50 text-amber-600"
                />
                <ActionTile label="Saved Items" icon={Heart} href={null} accent="bg-rose-50 text-rose-600" />
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
                            href={route('account.settings')}
                            className="text-xs font-semibold text-brand-600 hover:underline"
                        >
                            Edit
                        </Link>
                    </header>
                    <div className="space-y-3 px-5 py-4">
                        <p className="text-base font-bold text-gray-900">{account.name}</p>
                        <div>
                            <p className="flex items-center gap-2 text-sm text-gray-600">
                                {account.email ?? 'No email added'}
                                {account.email && (
                                    <Badge tone={account.emailVerified ? 'success' : 'warning'}>
                                        {account.emailVerified ? 'verified' : 'unverified'}
                                    </Badge>
                                )}
                            </p>
                            <p className="mt-1.5 flex items-center gap-2 text-sm text-gray-600">
                                {account.phone ?? 'No phone added'}
                                {account.phone && (
                                    <Badge tone={account.phoneVerified ? 'success' : 'warning'}>
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

                {/* Wallet balance (Jumia store-credit card) */}
                <section className="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <header className="border-b border-gray-100 px-5 py-3">
                        <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Wallet balance</h2>
                    </header>
                    <div className="px-5 py-4">
                        <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-900 p-5 text-white">
                            <span
                                className="pointer-events-none absolute -right-3 -top-4 select-none text-7xl leading-none opacity-10"
                                aria-hidden="true"
                            >
                                ₦
                            </span>
                            <p className="relative z-[1] text-xs font-semibold uppercase tracking-wide text-brand-100">
                                Available balance
                            </p>
                            <p className="relative z-[1] mt-1 text-3xl font-extrabold tracking-tight">
                                {formatNairaFromKobo(walletBalanceKobo)}
                            </p>
                        </div>
                        <div className="mt-3 flex items-center gap-3">
                            <Link
                                href={route('wallet.add-money')}
                                className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                            >
                                <Plus className="h-4 w-4" /> Add money
                            </Link>
                            <Link
                                href={route('wallet.index')}
                                className="text-sm font-semibold text-brand-600 hover:underline"
                            >
                                View wallet
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </AccountLayout>
    );
}
