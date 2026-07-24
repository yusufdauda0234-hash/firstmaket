import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    Lock,
    PackageCheck,
    PauseCircle,
    PiggyBank,
    PlayCircle,
    Store,
    Truck,
    Wallet,
} from 'lucide-react';
import { FormEventHandler } from 'react';

interface BundleItem {
    id: number;
    productName: string;
    productImage: string | null;
    vendorName: string;
    lockedPriceKobo: number;
    quantity: number;
}

interface Props {
    plan: {
        uuid: string;
        targetPriceKobo: number;
        amountSavedKobo: number;
        remainingKobo: number;
        progress: number;
        cadence: string | null;
        suggestedContributionKobo: number | null;
        status: string;
        pauseReason: string | null;
        expectedCompletionDate: string | null;
        startedAt: string | null;
        readyForDeliveryAt: string | null;
        items: BundleItem[];
    };
    walletBalanceKobo: number;
    openSavingsBalanceKobo: number;
    orderUuids: string[];
    [key: string]: unknown;
}

export default function BundlePlanShow() {
    const { plan, walletBalanceKobo, openSavingsBalanceKobo, orderUuids } = usePage<Props>().props;

    const contributeForm = useForm({ amount_naira: '', source: 'wallet' });
    const actionForm = useForm({});
    const addressForm = useForm({ plan_uuid: plan.uuid, delivery_address: '', state: '', lga: '' });

    const submitContribution: FormEventHandler = (e) => {
        e.preventDefault();
        contributeForm.post(route('savings.plans.contribute', plan.uuid), {
            preserveScroll: true,
            onSuccess: () => contributeForm.reset('amount_naira'),
        });
    };

    const isActive = plan.status === 'active';
    const isPaused = plan.status === 'paused';
    const isReady = plan.status === 'ready_for_delivery';
    const hasOrders = orderUuids.length > 0;

    return (
        <AccountLayout title="Bundle Tracker">
            <Head title="Bundle plan" />

            <Link
                href={route('savings.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> All savings
            </Link>

            {/* ── Tracker hero ── */}
            <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-900 p-6 text-white shadow-lg sm:p-7">
                <div className="relative z-[1]">
                    <p className="text-lg font-extrabold tracking-tight">
                        {plan.items.length} products bundled together
                    </p>
                    <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-100">
                        <span className="inline-flex items-center gap-1">
                            <Lock className="h-3.5 w-3.5" /> Combined target {formatNairaFromKobo(plan.targetPriceKobo)}
                        </span>
                        {plan.cadence && <span className="capitalize">{plan.cadence} plan</span>}
                        {plan.startedAt && <span>Started {plan.startedAt}</span>}
                    </p>
                    {isPaused && (
                        <span className="mt-2 inline-flex items-center gap-1 rounded-full bg-amber-400/20 px-3 py-1 text-xs font-bold text-amber-100">
                            <PauseCircle className="h-3.5 w-3.5" /> Paused
                        </span>
                    )}
                </div>

                <div className="relative z-[1] mt-5">
                    <div className="flex items-baseline justify-between">
                        <p className="text-2xl font-extrabold tracking-tight sm:text-3xl">
                            {formatNairaFromKobo(plan.amountSavedKobo)}
                            <span className="text-sm font-semibold text-brand-100">
                                {' '}
                                of {formatNairaFromKobo(plan.targetPriceKobo)}
                            </span>
                        </p>
                        <p className="text-xl font-extrabold text-brand-yellow">{Math.floor(plan.progress)}%</p>
                    </div>
                    <div className="mt-2 h-3 overflow-hidden rounded-full bg-white/15">
                        <div
                            className="h-full rounded-full bg-gradient-to-r from-brand-yellow to-yellow-300 transition-all"
                            style={{ width: `${Math.min(100, plan.progress)}%` }}
                        />
                    </div>
                    <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-brand-100">
                        <span>{formatNairaFromKobo(plan.remainingKobo)} remaining</span>
                        {isActive && plan.expectedCompletionDate && (
                            <span className="inline-flex items-center gap-1">
                                <CalendarClock className="h-3.5 w-3.5" /> Expected completion{' '}
                                {plan.expectedCompletionDate}
                            </span>
                        )}
                    </div>
                </div>
            </div>

            {/* ── Bundled products ── */}
            <Card className="mt-4 p-0">
                <h2 className="border-b border-gray-100 px-5 py-4 text-sm font-bold text-gray-900">
                    Products in this bundle
                </h2>
                <ul className="divide-y divide-gray-100">
                    {plan.items.map((item) => (
                        <li key={item.id} className="flex items-center gap-4 px-5 py-3.5">
                            <span className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                {item.productImage ? (
                                    <img src={item.productImage} alt="" className="h-full w-full object-cover" />
                                ) : (
                                    <PackageCheck className="h-5 w-5 text-gray-300" />
                                )}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-semibold text-gray-900">{item.productName}</p>
                                <p className="flex items-center gap-1 text-xs text-gray-400">
                                    <Store className="h-3 w-3" /> {item.vendorName} · Qty {item.quantity}
                                </p>
                            </div>
                            <p className="text-sm font-bold text-gray-900">
                                {formatNairaFromKobo(item.lockedPriceKobo * item.quantity)}
                            </p>
                        </li>
                    ))}
                </ul>
            </Card>

            {/* ── Ready for delivery: track existing orders or capture address ── */}
            {hasOrders && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4">
                    <p className="flex items-center gap-2 text-sm text-emerald-900">
                        <Truck className="h-5 w-5 shrink-0 text-emerald-600" />
                        Your {orderUuids.length} orders are placed and moving through delivery.
                    </p>
                    <Link
                        href={route('orders.index')}
                        className="rounded-full bg-emerald-600 px-5 py-2 text-sm font-bold text-white transition hover:bg-emerald-700 active:scale-95"
                    >
                        Track my orders →
                    </Link>
                </div>
            )}

            {isReady && !hasOrders && (
                <div className="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                    <p className="flex items-center gap-2 text-sm font-bold text-emerald-900">
                        <Truck className="h-5 w-5 text-emerald-600" /> Fully funded — where should we deliver it? 🎉
                    </p>
                    <p className="mt-1 text-sm text-emerald-800">
                        {plan.readyForDeliveryAt && `Reached 100% on ${plan.readyForDeliveryAt}. `}
                        One address places every order in this bundle at once — each vendor is notified immediately.
                    </p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            addressForm.post(route('orders.store'));
                        }}
                        className="mt-4 grid gap-3 sm:grid-cols-2"
                    >
                        <div className="sm:col-span-2">
                            <input
                                type="text"
                                placeholder="Street address (house number, street, area)"
                                value={addressForm.data.delivery_address}
                                onChange={(e) => addressForm.setData('delivery_address', e.target.value)}
                                required
                                className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                            />
                            <InputError message={addressForm.errors.delivery_address} className="mt-1" />
                        </div>
                        <div>
                            <input
                                type="text"
                                placeholder="State (e.g. Lagos)"
                                value={addressForm.data.state}
                                onChange={(e) => addressForm.setData('state', e.target.value)}
                                required
                                className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                            />
                            <InputError message={addressForm.errors.state} className="mt-1" />
                        </div>
                        <div>
                            <input
                                type="text"
                                placeholder="LGA"
                                value={addressForm.data.lga}
                                onChange={(e) => addressForm.setData('lga', e.target.value)}
                                required
                                className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                            />
                            <InputError message={addressForm.errors.lga} className="mt-1" />
                        </div>
                        <div className="sm:col-span-2">
                            <button
                                type="submit"
                                disabled={addressForm.processing}
                                className="w-full rounded-full bg-emerald-600 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700 active:scale-[0.98] disabled:opacity-60"
                            >
                                {addressForm.processing ? 'Placing orders…' : 'Place my orders'}
                            </button>
                            <InputError message={(addressForm.errors as Record<string, string>).plan} className="mt-1" />
                        </div>
                    </form>
                </div>
            )}

            {isPaused && plan.pauseReason && (
                <p className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-800">
                    Paused: {plan.pauseReason}
                </p>
            )}

            <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_320px]">
                <div className="space-y-4">
                    {isActive && (
                        <Card>
                            <h2 className="text-sm font-bold text-gray-900">Add a contribution</h2>
                            <form onSubmit={submitContribution} className="mt-3">
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <button
                                        type="button"
                                        onClick={() => contributeForm.setData('source', 'wallet')}
                                        className={cn(
                                            'flex items-center gap-2.5 rounded-xl border px-3.5 py-3 text-left transition active:scale-[0.98]',
                                            contributeForm.data.source === 'wallet'
                                                ? 'border-brand-600 bg-brand-50'
                                                : 'border-gray-200 hover:border-brand-300',
                                        )}
                                    >
                                        <Wallet className="h-4 w-4 shrink-0 text-brand-600" />
                                        <span>
                                            <span className="block text-sm font-bold text-gray-900">From wallet</span>
                                            <span className="block text-xs text-gray-400">
                                                {formatNairaFromKobo(walletBalanceKobo)} available
                                            </span>
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => contributeForm.setData('source', 'open_savings')}
                                        className={cn(
                                            'flex items-center gap-2.5 rounded-xl border px-3.5 py-3 text-left transition active:scale-[0.98]',
                                            contributeForm.data.source === 'open_savings'
                                                ? 'border-brand-600 bg-brand-50'
                                                : 'border-gray-200 hover:border-brand-300',
                                        )}
                                    >
                                        <PiggyBank className="h-4 w-4 shrink-0 text-brand-600" />
                                        <span>
                                            <span className="block text-sm font-bold text-gray-900">
                                                From Open Savings
                                            </span>
                                            <span className="block text-xs text-gray-400">
                                                {formatNairaFromKobo(openSavingsBalanceKobo)} available
                                            </span>
                                        </span>
                                    </button>
                                </div>

                                <div className="mt-3 flex items-center gap-2">
                                    <div className="flex flex-1 items-center rounded-full border border-gray-200 px-4 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/15">
                                        <span className="text-sm font-bold text-gray-400">₦</span>
                                        <input
                                            type="number"
                                            min="100"
                                            step="1"
                                            inputMode="numeric"
                                            placeholder={
                                                plan.suggestedContributionKobo
                                                    ? `Suggested ${(plan.suggestedContributionKobo / 100).toLocaleString()}`
                                                    : 'Amount'
                                            }
                                            value={contributeForm.data.amount_naira}
                                            onChange={(e) => contributeForm.setData('amount_naira', e.target.value)}
                                            className="w-full border-0 bg-transparent px-2 py-2.5 text-sm font-bold text-gray-900 focus:outline-none focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
                                        />
                                    </div>
                                    <button
                                        type="submit"
                                        disabled={contributeForm.processing}
                                        className="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                                    >
                                        {contributeForm.processing ? 'Applying…' : 'Contribute'}
                                    </button>
                                </div>
                                <InputError message={contributeForm.errors.amount_naira} className="mt-1.5" />
                                <InputError message={(contributeForm.errors as Record<string, string>).amount} className="mt-1.5" />
                                <InputError message={(contributeForm.errors as Record<string, string>).plan} className="mt-1.5" />
                            </form>
                        </Card>
                    )}
                </div>

                <div className="space-y-3">
                    {(isActive || isPaused) && (
                        <Card>
                            <h3 className="text-sm font-bold text-gray-900">Plan controls</h3>
                            <p className="mt-1 text-xs text-gray-500">
                                Pausing keeps your money locked to this bundle and every price unchanged.
                            </p>
                            {isActive ? (
                                <button
                                    type="button"
                                    disabled={actionForm.processing}
                                    onClick={() =>
                                        actionForm.post(route('savings.plans.pause', plan.uuid), {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-full border border-gray-200 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50 active:scale-95"
                                >
                                    <PauseCircle className="h-4 w-4" /> Pause plan
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    disabled={actionForm.processing}
                                    onClick={() =>
                                        actionForm.post(route('savings.plans.resume', plan.uuid), {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-full bg-brand-600 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                                >
                                    <PlayCircle className="h-4 w-4" /> Resume plan
                                </button>
                            )}
                        </Card>
                    )}
                </div>
            </div>
        </AccountLayout>
    );
}
