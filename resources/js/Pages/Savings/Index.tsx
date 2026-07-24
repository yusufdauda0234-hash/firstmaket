import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarClock, PackageCheck, PauseCircle, PiggyBank, Plus, ShoppingBag, Target, Wallet } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface PlanRow {
    uuid: string;
    isBundle: boolean;
    productName: string;
    productSlug: string | null;
    productImage: string | null;
    targetPriceKobo: number;
    amountSavedKobo: number;
    remainingKobo: number;
    progress: number;
    paymentMode: string;
    cadence: string | null;
    status: string;
    expectedCompletionDate: string | null;
    startedAt: string | null;
}

interface Props {
    openSavingsBalanceKobo: number;
    walletBalanceKobo: number;
    plans: PlanRow[];
    activePlanCount: number;
    [key: string]: unknown;
}

const statusStyle: Record<string, string> = {
    active: 'bg-emerald-50 text-emerald-700',
    paused: 'bg-amber-50 text-amber-700',
    ready_for_delivery: 'bg-brand-50 text-brand-700',
    completed: 'bg-gray-100 text-gray-600',
    cancelled: 'bg-gray-100 text-gray-400',
};

const statusLabel: Record<string, string> = {
    active: 'Active',
    paused: 'Paused',
    ready_for_delivery: 'Ready for delivery',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export default function SavingsIndex() {
    const { openSavingsBalanceKobo, walletBalanceKobo, plans, activePlanCount } = usePage<Props>().props;

    const [allocating, setAllocating] = useState(false);
    const allocateForm = useForm({ amount_naira: '' });

    const submitAllocation: FormEventHandler = (e) => {
        e.preventDefault();
        allocateForm.post(route('savings.open.allocate'), {
            preserveScroll: true,
            onSuccess: () => {
                allocateForm.reset();
                setAllocating(false);
            },
        });
    };

    return (
        <AccountLayout title="Savings">
            <Head title="My Savings" />

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">My Savings</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Save openly or lock a product price and pay small small.
                    </p>
                </div>
                <Link
                    href={route('catalog.index')}
                    className="flex items-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-brand-700 hover:shadow-lg active:scale-95"
                >
                    <ShoppingBag className="h-4 w-4" /> Find a product to save for
                </Link>
            </div>

            {/* ── Open Savings hero ── */}
            <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-900 p-6 text-white shadow-lg sm:p-7">
                <PiggyBank
                    className="pointer-events-none absolute -right-4 -top-6 h-36 w-36 select-none opacity-10"
                    aria-hidden="true"
                />
                <div className="relative z-[1] flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-brand-100">
                            <PiggyBank className="h-4 w-4" /> Open Savings balance
                        </p>
                        <p className="mt-2 text-4xl font-extrabold tracking-tight">
                            {formatNairaFromKobo(openSavingsBalanceKobo)}
                        </p>
                        <p className="mt-2 text-xs text-brand-100">
                            No target — redirect it into a product plan anytime. Never withdrawable as cash.
                        </p>
                    </div>
                    <div className="flex flex-col items-end gap-2">
                        <button
                            type="button"
                            onClick={() => setAllocating((v) => !v)}
                            className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-4 py-2 text-sm font-bold text-white backdrop-blur transition hover:bg-white/25"
                        >
                            <Plus className="h-4 w-4" /> Add from wallet
                        </button>
                        <p className="flex items-center gap-1 text-xs text-brand-100">
                            <Wallet className="h-3.5 w-3.5" /> Wallet: {formatNairaFromKobo(walletBalanceKobo)}
                        </p>
                    </div>
                </div>

                {allocating && (
                    <form
                        onSubmit={submitAllocation}
                        className="relative z-[1] mt-4 flex flex-wrap items-start gap-2 rounded-2xl bg-white/10 p-3 backdrop-blur"
                    >
                        <div className="min-w-[180px] flex-1">
                            <div className="flex items-center rounded-full bg-white px-4">
                                <span className="text-sm font-bold text-gray-400">₦</span>
                                <input
                                    type="number"
                                    min="100"
                                    step="1"
                                    inputMode="numeric"
                                    autoFocus
                                    placeholder="Amount to move"
                                    value={allocateForm.data.amount_naira}
                                    onChange={(e) => allocateForm.setData('amount_naira', e.target.value)}
                                    className="w-full border-0 bg-transparent px-2 py-2.5 text-sm font-semibold text-gray-900 focus:outline-none focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
                                />
                            </div>
                            <InputError message={allocateForm.errors.amount_naira} className="mt-1 text-brand-50" />
                        </div>
                        <button
                            type="submit"
                            disabled={allocateForm.processing}
                            className="rounded-full bg-brand-yellow px-5 py-2.5 text-sm font-bold text-brand-900 transition hover:bg-yellow-300 active:scale-95 disabled:opacity-60"
                        >
                            {allocateForm.processing ? 'Moving…' : 'Move to savings'}
                        </button>
                    </form>
                )}
            </div>

            {/* ── Plans ── */}
            <div className="mt-6 flex items-center justify-between">
                <h2 className="flex items-center gap-2 font-bold text-gray-900">
                    <Target className="h-4 w-4 text-brand-600" />
                    Product Target Plans
                    {activePlanCount > 0 && (
                        <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-bold text-brand-700">
                            {activePlanCount} active
                        </span>
                    )}
                </h2>
            </div>

            {plans.length === 0 ? (
                <Card className="mt-3 flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Target className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No plans yet</p>
                    <p className="mt-1 max-w-sm text-sm text-gray-500">
                        Pick any product and choose “Save Small Small” — today's price gets locked while you save
                        toward it.
                    </p>
                    <Link
                        href={route('catalog.index')}
                        className="mt-4 inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                    >
                        Browse the catalog <ArrowRight className="h-4 w-4" />
                    </Link>
                </Card>
            ) : (
                <div className="mt-3 grid gap-4 sm:grid-cols-2">
                    {plans.map((plan) => (
                        <Link
                            key={plan.uuid}
                            href={route('savings.plans.show', plan.uuid)}
                            className="group rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md"
                        >
                            <div className="flex items-start gap-3">
                                <span className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                    {plan.productImage ? (
                                        <img src={plan.productImage} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                        <PackageCheck className="h-6 w-6 text-gray-300" />
                                    )}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="truncate text-sm font-bold text-gray-900 group-hover:text-brand-700">
                                            {plan.productName}
                                        </p>
                                        <span
                                            className={cn(
                                                'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                                statusStyle[plan.status] ?? 'bg-gray-100 text-gray-500',
                                            )}
                                        >
                                            {plan.status === 'paused' && <PauseCircle className="mr-0.5 inline h-3 w-3" />}
                                            {statusLabel[plan.status] ?? plan.status}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        {plan.paymentMode === 'pay_at_once'
                                            ? 'Paid at once'
                                            : plan.cadence
                                              ? `${plan.cadence.charAt(0).toUpperCase() + plan.cadence.slice(1)} plan`
                                              : 'Plan'}
                                        {plan.startedAt && ` · started ${plan.startedAt}`}
                                    </p>
                                </div>
                            </div>

                            {/* Progress */}
                            <div className="mt-3">
                                <div className="flex items-baseline justify-between text-xs">
                                    <span className="font-bold text-gray-900">
                                        {formatNairaFromKobo(plan.amountSavedKobo)}
                                        <span className="font-medium text-gray-400">
                                            {' '}
                                            / {formatNairaFromKobo(plan.targetPriceKobo)}
                                        </span>
                                    </span>
                                    <span className="font-bold text-brand-700">{Math.floor(plan.progress)}%</span>
                                </div>
                                <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-100">
                                    <div
                                        className="h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-700 transition-all"
                                        style={{ width: `${Math.min(100, plan.progress)}%` }}
                                    />
                                </div>
                                {plan.status === 'active' && plan.expectedCompletionDate && (
                                    <p className="mt-1.5 flex items-center gap-1 text-[11px] text-gray-400">
                                        <CalendarClock className="h-3 w-3" /> Expected completion{' '}
                                        {plan.expectedCompletionDate}
                                    </p>
                                )}
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </AccountLayout>
    );
}
