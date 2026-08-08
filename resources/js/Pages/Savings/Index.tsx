import AccountLayout from '@/Layouts/AccountLayout';
import { PageProps } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, PiggyBank, ShoppingBag } from 'lucide-react';

interface PlanRow {
    uuid: string;
    status: 'saving' | 'fulfilled' | 'cancelled';
    targetKobo: number;
    paidKobo: number;
    remainingKobo: number;
    progress: number;
    canFulfil: boolean;
    cadenceLabel: string | null;
    installmentKobo: number;
    installments: number;
    installmentsPaid: number;
    nextDueAt: string | null;
    itemCount: number;
    title: string;
    image: string | null;
    startedAt: string | null;
    fulfilledAt: string | null;
}

interface Props extends PageProps {
    goals: PlanRow[];
    activeCount: number;
    planCreditKobo: number;
}

/**
 * The customer's Pay Small Small plans. There is no balance here — money
 * lives inside the plan it was paid into, so every figure on this page
 * belongs to a specific product.
 */
export default function SavingsIndex() {
    const { goals, activeCount, planCreditKobo } = usePage<Props>().props;

    const running = goals.filter((goal) => goal.status === 'saving');
    const settled = goals.filter((goal) => goal.status !== 'saving');

    return (
        <AccountLayout title="Pay Small Small">
            <Head title="Pay Small Small" />

            <div className="overflow-hidden rounded-2xl bg-gradient-to-br from-brand-700 to-brand-900 p-6 text-white shadow-lg">
                <p className="flex items-center gap-2 text-sm font-medium text-brand-100">
                    <PiggyBank className="h-4 w-4" /> Pay Small Small
                </p>
                <p className="mt-1 text-3xl font-extrabold tracking-tight">
                    {activeCount} active plan{activeCount === 1 ? '' : 's'}
                </p>
                <p className="mt-2 max-w-md text-sm leading-relaxed text-brand-100">
                    Lock a price today and pay it off bit by bit. We deliver once the last instalment lands — no
                    interest, no loan, and the price never moves.
                </p>

                {planCreditKobo > 0 && (
                    <p className="mt-4 inline-block rounded-full bg-white/15 px-4 py-2 text-sm font-semibold">
                        {formatNairaFromKobo(planCreditKobo)} credit waiting — it goes onto your next plan
                        automatically.
                    </p>
                )}
            </div>

            <section className="mt-6">
                <h2 className="text-lg font-extrabold text-gray-900">Your plans</h2>

                {running.length === 0 ? (
                    <div className="mt-3 rounded-2xl border border-dashed border-gray-200 bg-white px-6 py-10 text-center">
                        <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                            <PiggyBank className="h-6 w-6" />
                        </span>
                        <p className="mt-3 text-sm font-semibold text-gray-900">No plans running</p>
                        <p className="mt-1 text-sm text-gray-500">
                            At checkout, choose <strong>Pay Small Small</strong> to lock a price and pay it off over
                            time.
                        </p>
                        <Link
                            href={route('catalog.index')}
                            className="mt-4 inline-block rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                        >
                            Start shopping
                        </Link>
                    </div>
                ) : (
                    <ul className="mt-3 space-y-3">
                        {running.map((goal) => (
                            <li key={goal.uuid}>
                                <Link
                                    href={route('savings.goals.show', goal.uuid)}
                                    className="flex gap-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm transition hover:border-brand-200 hover:shadow-md"
                                >
                                    <span className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                        {goal.image ? (
                                            <img src={goal.image} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <ShoppingBag className="h-7 w-7 text-gray-300" />
                                        )}
                                    </span>

                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-start justify-between gap-3">
                                            <span className="line-clamp-1 text-sm font-semibold text-gray-900">
                                                {goal.title}
                                            </span>
                                            <span className="shrink-0 text-sm font-extrabold tabular-nums text-gray-900">
                                                {formatNairaFromKobo(goal.targetKobo)}
                                            </span>
                                        </span>

                                        <span className="mt-0.5 block text-xs text-gray-400">
                                            {formatNairaFromKobo(goal.installmentKobo)}{' '}
                                            {goal.cadenceLabel?.toLowerCase()} · payment {goal.installmentsPaid} of{' '}
                                            {goal.installments}
                                        </span>

                                        <span className="mt-2.5 block h-2 overflow-hidden rounded-full bg-gray-100">
                                            <span
                                                className={`block h-full rounded-full transition-all ${
                                                    goal.canFulfil ? 'bg-emerald-500' : 'bg-brand-600'
                                                }`}
                                                style={{ width: `${goal.progress}%` }}
                                            />
                                        </span>

                                        <span className="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-xs">
                                            <span className="text-gray-500">
                                                {formatNairaFromKobo(goal.paidKobo)} paid
                                            </span>
                                            {goal.canFulfil ? (
                                                <span className="font-bold text-emerald-600">
                                                    Fully paid — collect it →
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1 font-semibold text-gray-400">
                                                    {goal.nextDueAt && (
                                                        <>
                                                            <CalendarClock className="h-3 w-3" /> next {goal.nextDueAt}{' '}
                                                            ·{' '}
                                                        </>
                                                    )}
                                                    {formatNairaFromKobo(goal.remainingKobo)} to go
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {settled.length > 0 && (
                <section className="mt-8">
                    <h2 className="text-lg font-extrabold text-gray-900">Past plans</h2>
                    <ul className="mt-3 divide-y divide-gray-100 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                        {settled.map((goal) => (
                            <li key={goal.uuid} className="flex items-center gap-3 px-5 py-3.5">
                                {goal.status === 'fulfilled' && (
                                    <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                                )}
                                <span className="min-w-0 flex-1">
                                    <Link
                                        href={route('savings.goals.show', goal.uuid)}
                                        className="block truncate text-sm font-medium text-gray-900 hover:text-brand-600"
                                    >
                                        {goal.title}
                                    </Link>
                                    <span className="block text-xs text-gray-400">
                                        {goal.status === 'fulfilled'
                                            ? `Collected ${goal.fulfilledAt}`
                                            : `Cancelled · ${formatNairaFromKobo(goal.paidKobo)} moved to credit`}
                                    </span>
                                </span>
                                <span className="text-sm font-semibold tabular-nums text-gray-500">
                                    {formatNairaFromKobo(goal.targetKobo)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AccountLayout>
    );
}
