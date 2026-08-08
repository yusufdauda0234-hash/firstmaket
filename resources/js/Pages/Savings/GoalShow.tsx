import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import SwitchItemsDialog from '@/Components/domain/savings/SwitchItemsDialog';
import AccountLayout from '@/Layouts/AccountLayout';
import { PageProps } from '@/Types';
import { productLinkProps } from '@/Utils/links';
import { formatNairaFromKobo, formatNumber } from '@/Utils/money';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CalendarClock, CheckCircle2, Lock, Repeat, ShoppingBag, Truck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { MoneyInput } from '@/Components/ui/MoneyInput';

interface GoalItem {
    productUuid: string;
    productName: string;
    productSlug: string;
    productImage: string | null;
    quantity: number;
    lockedUnitPriceKobo: number;
    currentUnitPriceKobo: number;
    lineTotalKobo: number;
}

interface Payment {
    uuid: string;
    amountKobo: number;
    source: string;
    at: string;
}

interface PlanTerm {
    id: number;
    name: string;
    cadenceLabel: string;
    installments: number;
    durationMonths: number;
    durationLabel: string;
    minTargetKobo: number;
    paysUpfront: boolean;
}

/** A candidate the plan could be switched to. */
interface Props extends PageProps {
    goal: {
        uuid: string;
        status: 'saving' | 'fulfilled' | 'cancelled';
        targetKobo: number;
        paidKobo: number;
        remainingKobo: number;
        progress: number;
        canFulfil: boolean;
        cadenceLabel: string | null;
        installments: number;
        installmentsPaid: number;
        installmentKobo: number;
        nextPaymentKobo: number;
        nextDueAt: string | null;
        startedAt: string | null;
        fulfilledAt: string | null;
        deliveryAddress: string | null;
        state: string | null;
        lga: string | null;
        recipientName: string | null;
        recipientPhone: string | null;
        landmark: string | null;
        switchesUsed: number;
        switchesAllowed: number;
        canSwitch: boolean;
        canReschedule: boolean;
        extensionUsed: boolean;
        behindOnPayments: boolean;
        durationMonths: number | null;
        items: GoalItem[];
        payments: Payment[];
    };
    planTerms: PlanTerm[];
}

/**
 * One Pay Small Small plan: the locked basket, how much of it is paid off,
 * and the button that takes the next instalment. Money paid here belongs to
 * this plan — cancelling moves it to credit rather than losing it.
 */
export default function GoalShow() {
    const { goal, planTerms, errors } = usePage<Props>().props;
    const [payingMore, setPayingMore] = useState(false);
    const [changing, setChanging] = useState<'item' | 'schedule' | null>(null);

    const form = useForm({ amount_naira: '' as number | '' });
    const isRunning = goal.status === 'saving';

    const pay: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('savings.goals.pay', goal.uuid));
    };

    return (
        <AccountLayout title="Payment plan">
            <Head title="Payment plan" />

            <Link
                href={route('savings.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Back to my plans
            </Link>

            <InputError message={(errors as Record<string, string>).goal} className="mb-3" />

            {/* ── Progress ── */}
            <div className="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h1 className="text-lg font-extrabold text-gray-900">
                        {goal.status === 'fulfilled'
                            ? 'Plan complete'
                            : goal.status === 'cancelled'
                              ? 'Cancelled plan'
                              : 'Paying small small'}
                    </h1>
                    <span className="inline-flex items-center gap-1 text-xs font-semibold text-gray-400">
                        <Lock className="h-3.5 w-3.5" /> Price locked {goal.startedAt}
                    </span>
                </div>

                <p className="mt-4 flex items-baseline gap-2">
                    <span className="text-3xl font-extrabold tracking-tight text-gray-900">
                        {formatNairaFromKobo(goal.paidKobo)}
                    </span>
                    <span className="text-sm text-gray-400">of {formatNairaFromKobo(goal.targetKobo)}</span>
                </p>

                <div className="mt-3 h-2.5 overflow-hidden rounded-full bg-gray-100">
                    <div
                        className={`h-full rounded-full transition-all duration-500 ${
                            goal.canFulfil ? 'bg-emerald-500' : 'bg-brand-600'
                        }`}
                        style={{ width: `${goal.progress}%` }}
                    />
                </div>

                {isRunning && goal.cadenceLabel && (
                    <p className="mt-2 flex flex-wrap items-center gap-x-3 text-xs text-gray-500">
                        <span>
                            {formatNairaFromKobo(goal.installmentKobo)} {goal.cadenceLabel.toLowerCase()} · payment{' '}
                            {goal.installmentsPaid} of {goal.installments}
                        </span>
                        {goal.nextDueAt && !goal.canFulfil && (
                            <span className="inline-flex items-center gap-1">
                                <CalendarClock className="h-3 w-3" /> next due {goal.nextDueAt}
                            </span>
                        )}
                    </p>
                )}

                {isRunning &&
                    (goal.canFulfil ? (
                        <>
                            <p className="mt-3 flex items-center gap-1.5 text-sm font-semibold text-emerald-600">
                                <CheckCircle2 className="h-4 w-4" /> Fully paid — collect it now.
                            </p>
                            <button
                                type="button"
                                onClick={() => router.post(route('savings.goals.buy', goal.uuid))}
                                className="mt-4 w-full rounded-full bg-brand-600 py-3.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-[0.98]"
                            >
                                Collect my order
                            </button>
                        </>
                    ) : (
                        <form onSubmit={pay} className="mt-4">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="w-full rounded-full bg-brand-600 py-3.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-[0.98] disabled:opacity-60"
                            >
                                {form.processing
                                    ? 'Taking you to Paystack…'
                                    : `Pay ${formatNairaFromKobo(goal.nextPaymentKobo)} now`}
                            </button>

                            {payingMore ? (
                                <div className="mt-3">
                                    <label htmlFor="amount_naira" className="mb-1.5 block text-xs font-bold text-gray-700">
                                        Pay a different amount
                                    </label>
                                    <div className="flex gap-2">
                                        <MoneyInput
                                            id="amount_naira"
                                            min={100}
                                            max={goal.remainingKobo / 100}
                                            placeholder={formatNumber(Math.round(goal.remainingKobo / 100))}
                                            value={form.data.amount_naira}
                                            onChange={(value) => form.setData('amount_naira', value)}
                                        />
                                        <button
                                            type="submit"
                                            disabled={form.processing}
                                            className="shrink-0 rounded-xl bg-gray-900 px-4 text-xs font-bold text-white transition hover:bg-gray-800 disabled:opacity-60"
                                        >
                                            Pay
                                        </button>
                                    </div>
                                    <p className="mt-1 text-xs text-gray-400">
                                        Pay ahead to finish sooner — never more than the{' '}
                                        {formatNairaFromKobo(goal.remainingKobo)} left.
                                    </p>
                                    <InputError message={form.errors.amount_naira} className="mt-1" />
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setPayingMore(true)}
                                    className="mt-2 w-full text-center text-xs font-semibold text-gray-500 underline-offset-2 transition hover:text-brand-600 hover:underline"
                                >
                                    Pay a different amount
                                </button>
                            )}
                        </form>
                    ))}

                {goal.status === 'fulfilled' && (
                    <p className="mt-3 flex items-center gap-1.5 text-sm font-semibold text-emerald-600">
                        <CheckCircle2 className="h-4 w-4" /> Collected {goal.fulfilledAt} — track it in your orders.
                    </p>
                )}
            </div>

            {/* ── Items ── */}
            <div className="mt-5 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                <h2 className="border-b border-gray-100 px-5 py-3 text-sm font-bold text-gray-900">
                    What you are paying for
                </h2>
                <ul className="divide-y divide-gray-100">
                    {goal.items.map((item) => {
                        const risen = item.currentUnitPriceKobo > item.lockedUnitPriceKobo;

                        return (
                            <li key={item.productSlug} className="flex gap-4 px-5 py-4">
                                <a
                                    {...productLinkProps(item.productSlug)}
                                    className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50"
                                >
                                    {item.productImage ? (
                                        <img src={item.productImage} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                        <ShoppingBag className="h-6 w-6 text-gray-300" />
                                    )}
                                </a>
                                <div className="min-w-0 flex-1">
                                    <a
                                        {...productLinkProps(item.productSlug)}
                                        className="line-clamp-2 text-sm font-medium leading-snug text-gray-900 hover:text-brand-600"
                                    >
                                        {item.productName}
                                    </a>
                                    <p className="mt-1 text-xs text-gray-400">Qty {item.quantity}</p>
                                    {risen && (
                                        <p className="mt-1 text-xs font-semibold text-emerald-600">
                                            Now {formatNairaFromKobo(item.currentUnitPriceKobo)} — you keep the
                                            locked price
                                        </p>
                                    )}
                                </div>
                                <span className="self-center text-sm font-extrabold tabular-nums text-gray-900">
                                    {formatNairaFromKobo(item.lineTotalKobo)}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            {/* ── Change this plan ──
                High on the page, not buried under the delivery address: a
                customer having second thoughts is looking for a way out, and
                if the only thing they find is "cancel" they take it. */}
            {isRunning && (
                <div className="mt-5 rounded-2xl border border-brand-100 bg-brand-50/60 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-sm font-bold text-gray-900">Changed your mind?</p>
                            <p className="mt-0.5 text-xs text-gray-600">
                                You do not have to give this up — everything you have paid stays on the plan.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => setChanging('item')}
                                disabled={!goal.canSwitch}
                                className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400"
                            >
                                <Repeat className="h-4 w-4" /> Switch items
                            </button>
                            <button
                                type="button"
                                onClick={() => setChanging('schedule')}
                                className="inline-flex items-center gap-1.5 rounded-full border border-brand-300 bg-white px-4 py-2 text-sm font-bold text-brand-700 transition hover:bg-brand-50 active:scale-95"
                            >
                                <CalendarClock className="h-4 w-4" /> Change schedule
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* ── Payments ──
                A preview only. The full history has its own page: a plan runs
                for months, so the list outgrows this space, and reconciling
                against a bank statement wants references and paging. */}
            {goal.payments.length > 0 && (
                <div className="mt-5 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                    <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
                        <h2 className="text-sm font-bold text-gray-900">Recent payments</h2>
                        <Link
                            href={route('savings.goals.payments', goal.uuid)}
                            className="inline-flex items-center gap-1 text-sm font-bold text-brand-600 transition hover:text-brand-700"
                        >
                            All payments <ArrowRight className="h-3.5 w-3.5" />
                        </Link>
                    </div>
                    <ul className="divide-y divide-gray-100">
                        {goal.payments.slice(0, 3).map((payment) => (
                            <li key={payment.uuid} className="flex items-center justify-between px-5 py-3 text-sm">
                                <span>
                                    <span className="block font-medium text-gray-900">
                                        {payment.source === 'credit' ? 'Credit from a cancelled plan' : 'Card payment'}
                                    </span>
                                    <span className="block text-xs text-gray-400">{payment.at}</span>
                                </span>
                                <span className="font-bold tabular-nums text-emerald-600">
                                    +{formatNairaFromKobo(payment.amountKobo)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {goal.payments.length > 3 && (
                        <Link
                            href={route('savings.goals.payments', goal.uuid)}
                            className="block border-t border-gray-100 px-5 py-2.5 text-center text-sm font-semibold text-brand-600 transition hover:bg-brand-50/50"
                        >
                            View all {goal.payments.length} payments
                        </Link>
                    )}
                </div>
            )}

            {/* ── Delivery ── */}
            {goal.deliveryAddress && (
                <div className="mt-5 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h2 className="flex items-center gap-1.5 text-sm font-bold text-gray-900">
                        <Truck className="h-4 w-4 text-gray-400" /> Delivering to
                    </h2>
                    <p className="mt-1.5 text-sm leading-relaxed text-gray-500">
                        {goal.recipientName && (
                            <span className="font-semibold text-gray-800">
                                {goal.recipientName}
                                {goal.recipientPhone && ` · ${goal.recipientPhone}`}
                                <br />
                            </span>
                        )}
                        {goal.deliveryAddress}
                        <br />
                        {goal.lga}, {goal.state}
                    </p>
                </div>
            )}

            {/* Changing the plan comes first and cancelling is the quiet
                option underneath. Both keep the money — but "cancel" reads as
                loss, and most people reaching for it want a different item,
                not their money back (which they cannot have as cash anyway). */}
            {isRunning && (
                <div className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 className="text-sm font-bold text-gray-900">Stop this plan</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Switching items or changing the schedule is at the top of this page — both keep the
                        plan running. Cancelling ends it.
                    </p>

                    <div className="mt-4 hidden flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setChanging('schedule')}
                            disabled={!goal.canReschedule}
                            className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-5 py-2.5 text-sm font-bold text-gray-700 transition hover:border-brand-300 hover:text-brand-700 active:scale-95 disabled:cursor-not-allowed disabled:text-gray-300"
                        >
                            <CalendarClock className="h-4 w-4" /> Change the schedule
                        </button>
                    </div>

                    {!goal.canSwitch && (
                        <p className="mt-2 text-xs text-gray-400">
                            This plan has already been switched {goal.switchesAllowed} times, which is the
                            limit.
                        </p>
                    )}

                    <button
                        type="button"
                        onClick={() => {
                            if (
                                confirm(
                                    `Cancel this plan? Your ${formatNairaFromKobo(goal.paidKobo)} becomes credit ` +
                                        'towards a future plan. It cannot be paid back as cash.',
                                )
                            ) {
                                router.post(route('savings.goals.cancel', goal.uuid));
                            }
                        }}
                        className="mt-4 text-xs font-medium text-gray-400 underline underline-offset-2 transition hover:text-red-600"
                    >
                        Or cancel the plan — your {formatNairaFromKobo(goal.paidKobo)} becomes credit
                    </button>
                </div>
            )}

            {changing === 'schedule' && (
                <RescheduleModal goal={goal} terms={planTerms} onClose={() => setChanging(null)} />
            )}

            {changing === 'item' && (
                <SwitchItemsDialog
                    goalUuid={goal.uuid}
                    items={goal.items}
                    paidKobo={goal.paidKobo}
                    terms={planTerms}
                    onClose={() => setChanging(null)}
                />
            )}
        </AccountLayout>
    );
}

/** Pick a different rhythm. The item and its frozen price do not move. */
function RescheduleModal({
    goal,
    terms,
    onClose,
}: {
    goal: Props['goal'];
    terms: PlanTerm[];
    onClose: () => void;
}) {
    const form = useForm<{ plan_term_id: number | '' }>({ plan_term_id: '' });

    const affordable = terms.filter((term) => goal.targetKobo >= term.minTargetKobo);

    const submit = () => {
        form.post(route('savings.goals.reschedule', goal.uuid), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title="Change the schedule"
            description={`${formatNairaFromKobo(goal.remainingKobo)} left to pay. Your item and its locked price stay exactly as they are.`}
            size="xl"
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={form.data.plan_term_id === '' || form.processing}
                        className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                    >
                        {form.processing ? 'Saving…' : 'Use this schedule'}
                    </button>
                </>
            }
        >
            {goal.extensionUsed && (
                <p className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    This plan has already been extended once, so you can only choose a shorter run from here.
                    You can still pay it off faster at any time.
                </p>
            )}

            {goal.behindOnPayments && (
                <p className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    There are payments outstanding on this plan. Catch up first and it can then be extended.
                </p>
            )}

            <div className="grid gap-2.5 sm:grid-cols-2">
                {affordable.map((term) => {
                    const each = Math.ceil(goal.remainingKobo / Math.max(1, term.installments));
                    const active = form.data.plan_term_id === term.id;

                    return (
                        <button
                            key={term.id}
                            type="button"
                            onClick={() => form.setData('plan_term_id', term.id)}
                            className={`rounded-xl border-2 p-4 text-left transition ${
                                active
                                    ? 'border-brand-600 bg-brand-50/60'
                                    : 'border-gray-200 bg-white hover:border-brand-300'
                            }`}
                        >
                            <span className="block text-lg font-extrabold text-brand-700">
                                {formatNairaFromKobo(each)}
                                <span className="text-xs font-medium text-gray-500">
                                    {' '}
                                    / {term.cadenceLabel.toLowerCase()}
                                </span>
                            </span>
                            <span className="mt-1 block text-xs text-gray-600">
                                {term.installments} payments · {term.durationLabel}
                            </span>
                        </button>
                    );
                })}
            </div>

            <InputError message={form.errors.plan_term_id} className="mt-2" />
        </Modal>
    );
}

/** Point the plan at something else. Priced at today's price. */
