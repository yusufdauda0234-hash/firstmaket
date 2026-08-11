import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import { MoneyInput } from '@/Components/ui/MoneyInput';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Banknote, Check, Wallet } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Outstanding {
    id: number;
    uuid: string;
    name: string;
    phone: string | null;
    balanceKobo: number;
    pendingKobo: number;
    ceilingKobo: number;
}

interface Pending {
    uuid: string;
    courierName: string;
    amountKobo: number;
    note: string | null;
    declaredAt: string;
    /** Its own courier may not confirm it, so the button is not offered. */
    isOwn: boolean;
}

interface Movement {
    uuid: string;
    type: string;
    courierName: string;
    amountKobo: number;
    confirmedBy: string | null;
    at: string;
}

interface GoodsPayment {
    reference: string;
    amountKobo: number;
    payerName: string;
    method: string;
    status: string;
    at: string;
}

interface Props {
    outstanding: Outstanding[];
    pending: Pending[];
    recent: Movement[];
    goodsPayments: GoodsPayment[];
    settings: {
        enabled: boolean;
        maxOrderNaira: number;
        states: string[];
        maxRefusals: number;
    };
    allStates: string[];
    [key: string]: unknown;
}

const naira = new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 0,
});

const money = (kobo: number) => naira.format(kobo / 100);

/**
 * Cash on delivery: what is owed, who is holding it, and the bounds.
 *
 * One screen because it is one job — nobody sets the ceiling without seeing
 * what is currently walking around, and nobody chases a courier's balance
 * without wanting to know how it got that high.
 */
export default function Cash() {
    const {
        outstanding = [],
        pending = [],
        recent = [],
        goodsPayments = [],
        settings,
        allStates = [],
    } = usePage<Props>().props;

    const totalOut = outstanding.reduce((sum, row) => sum + row.balanceKobo, 0);

    return (
        <AdminLayout>
            <Head title="Cash on delivery" />

            <PageHeader
                title="Cash on delivery"
                description="Money customers pay at the door, and who is holding it until it reaches the office."
            />

            <div className="grid gap-3 sm:grid-cols-3">
                <Card className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Out with couriers
                    </p>
                    <p
                        className={`mt-1 text-2xl font-extrabold tabular-nums ${
                            totalOut > 0 ? 'text-amber-600' : 'text-gray-900'
                        }`}
                    >
                        {money(totalOut)}
                    </p>
                </Card>
                <Card className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Waiting to be confirmed
                    </p>
                    <p className="mt-1 text-2xl font-extrabold tabular-nums text-gray-900">
                        {money(pending.reduce((sum, row) => sum + row.amountKobo, 0))}
                    </p>
                </Card>
                <Card className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Pay on delivery
                    </p>
                    <p
                        className={`mt-1 text-2xl font-extrabold ${
                            settings.enabled ? 'text-emerald-600' : 'text-gray-400'
                        }`}
                    >
                        {settings.enabled ? 'On' : 'Off'}
                    </p>
                </Card>
            </div>

            {/* ── Hand-ins waiting on the office ── */}
            {pending.length > 0 && (
                <Card className="mt-4 border-amber-200 bg-amber-50/60 p-4">
                    <h2 className="flex items-center gap-2 text-sm font-extrabold text-amber-800">
                        <Banknote className="h-4 w-4" /> Money handed in, not yet confirmed
                    </h2>
                    <p className="mt-1 text-xs leading-relaxed text-amber-700">
                        A courier saying they paid it in is not the same as the office having it. It
                        stays on their balance until somebody confirms the cash arrived.
                    </p>

                    <ul className="mt-3 space-y-2">
                        {pending.map((row) => (
                            <li
                                key={row.uuid}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-3 py-2.5"
                            >
                                <span className="min-w-0">
                                    <span className="font-bold text-gray-900">{row.courierName}</span>
                                    <span className="ml-2 font-bold tabular-nums text-gray-900">
                                        {money(row.amountKobo)}
                                    </span>
                                    <span className="block text-xs text-gray-400">
                                        {row.declaredAt}
                                        {row.note && ` · ${row.note}`}
                                    </span>
                                </span>
                                {row.isOwn ? (
                                    <span className="text-xs font-semibold text-gray-400">
                                        Somebody else must confirm this
                                    </span>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                route('admin.cash.confirm', row.uuid),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-emerald-700"
                                    >
                                        <Check className="h-3.5 w-3.5" /> Confirm received
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_22rem]">
                {/* ── Who is holding what ── */}
                <Card className="overflow-hidden">
                    <h2 className="flex items-center gap-2 border-b border-gray-100 px-4 py-3 text-sm font-bold text-gray-900">
                        <Wallet className="h-4 w-4 text-gray-400" /> Couriers holding cash
                    </h2>

                    {outstanding.length === 0 ? (
                        <p className="px-4 py-12 text-center text-sm text-gray-400">
                            Nobody is carrying money right now.
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {outstanding.map((row) => {
                                const overCeiling =
                                    row.ceilingKobo > 0 && row.balanceKobo >= row.ceilingKobo;

                                return (
                                    <li
                                        key={row.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                                    >
                                        <span className="min-w-0">
                                            <span className="block font-bold text-gray-900">
                                                {row.name}
                                            </span>
                                            <span className="block text-xs text-gray-400">
                                                {row.phone ?? 'no phone'}
                                                {row.pendingKobo > 0 &&
                                                    ` · ${money(row.pendingKobo)} awaiting confirmation`}
                                            </span>
                                        </span>
                                        <span className="text-right">
                                            <span
                                                className={`block font-extrabold tabular-nums ${
                                                    overCeiling ? 'text-red-600' : 'text-gray-900'
                                                }`}
                                            >
                                                {money(row.balanceKobo)}
                                            </span>
                                            {row.ceilingKobo > 0 && (
                                                <span
                                                    className={`block text-[11px] ${
                                                        overCeiling
                                                            ? 'font-bold text-red-600'
                                                            : 'text-gray-400'
                                                    }`}
                                                >
                                                    {overCeiling ? 'at their limit' : `of ${money(row.ceilingKobo)}`}
                                                </span>
                                            )}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Card>

                <SettingsPanel settings={settings} allStates={allStates} />
            </div>

            {/* ── The trail ── */}
            {recent.length > 0 && (
                <Card className="mt-4 overflow-hidden">
                    <h2 className="border-b border-gray-100 px-4 py-3 text-sm font-bold text-gray-900">
                        Recent movements
                    </h2>
                    <ul className="divide-y divide-gray-50 text-sm">
                        {recent.map((row) => (
                            <li key={row.uuid} className="flex items-center justify-between px-4 py-2.5">
                                <span className="min-w-0">
                                    <span className="font-semibold text-gray-800">{row.courierName}</span>
                                    <span className="ml-2 text-xs text-gray-400">
                                        {row.type === 'collection' ? 'collected at a door' : 'handed in'}
                                        {row.confirmedBy && ` · confirmed by ${row.confirmedBy}`}
                                    </span>
                                </span>
                                <span className="shrink-0 text-right">
                                    <span
                                        className={`block font-bold tabular-nums ${
                                            row.type === 'collection' ? 'text-gray-900' : 'text-emerald-700'
                                        }`}
                                    >
                                        {row.type === 'collection' ? '+' : '−'}
                                        {money(row.amountKobo)}
                                    </span>
                                    <span className="block text-[11px] text-gray-400">{row.at}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {goodsPayments.length > 0 && (
                <Card className="mt-4 overflow-hidden">
                    <h2 className="border-b border-gray-100 px-4 py-3 text-sm font-bold text-gray-900">
                        Online goods payments
                    </h2>
                    <ul className="divide-y divide-gray-50 text-sm">
                        {goodsPayments.map((payment) => (
                            <li
                                key={payment.reference}
                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                            >
                                <span className="min-w-0">
                                    <span className="block font-semibold text-gray-800">{payment.payerName}</span>
                                    <span className="block text-xs text-gray-400">
                                        {payment.method === 'courier_online'
                                            ? 'Courier paid online'
                                            : 'Customer paid online'}{' '}
                                        · {payment.at}
                                    </span>
                                </span>
                                <span className="text-right">
                                    <span className="block font-bold tabular-nums text-emerald-700">
                                        {money(payment.amountKobo)}
                                    </span>
                                    <span className="block text-[11px] font-semibold uppercase text-gray-400">
                                        {payment.status}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </AdminLayout>
    );
}

function SettingsPanel({
    settings,
    allStates,
}: {
    settings: Props['settings'];
    allStates: string[];
}) {
    const form = useForm({
        enabled: settings.enabled,
        max_order_naira: settings.maxOrderNaira as number | '',
        states: settings.states,
        max_refusals: settings.maxRefusals,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.cash.settings'), { preserveScroll: true });
    };

    const toggleState = (state: string) =>
        form.setData(
            'states',
            form.data.states.includes(state)
                ? form.data.states.filter((s) => s !== state)
                : [...form.data.states, state],
        );

    return (
        <Card className="p-4">
            <h2 className="text-sm font-bold text-gray-900">Settings</h2>

            <form onSubmit={submit} className="mt-3 space-y-4">
                <label className="flex cursor-pointer items-start gap-2.5 rounded-xl bg-gray-50 px-3 py-2.5">
                    <input
                        type="checkbox"
                        checked={form.data.enabled}
                        onChange={(event) => form.setData('enabled', event.target.checked)}
                        className="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                    />
                    <span>
                        <span className="block text-sm font-bold text-gray-900">
                            Offer pay on delivery
                        </span>
                        <span className="block text-[11px] leading-relaxed text-gray-500">
                            Switching it off leaves orders already placed under it alone — that cash
                            still has to be collected.
                        </span>
                    </span>
                </label>

                <label className="block">
                    <span className="mb-1 block text-xs font-bold text-gray-700">
                        Largest order allowed
                    </span>
                    <MoneyInput
                        min={0}
                        value={form.data.max_order_naira}
                        onChange={(value: number | '') => form.setData('max_order_naira', value)}
                    />
                    {/* The exposure on one failed delivery, so it is worth
                        saying out loud rather than leaving as a number. */}
                    <p className="mt-1 text-[11px] leading-relaxed text-gray-400">
                        {Number(form.data.max_order_naira) === 0
                            ? 'No limit — a courier could be sent out holding any amount.'
                            : `Most a courier can be owed on one doorstep. 0 removes the limit.`}
                    </p>
                    <InputError message={form.errors.max_order_naira} className="mt-1" />
                </label>

                <label className="block">
                    <span className="mb-1 block text-xs font-bold text-gray-700">
                        Refusals before an account loses it
                    </span>
                    <input
                        type="number"
                        min={1}
                        max={20}
                        value={form.data.max_refusals}
                        onChange={(event) => form.setData('max_refusals', Number(event.target.value))}
                        className="border w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500/20 px-3 py-2 shadow-sm"
                    />
                    <p className="mt-1 text-[11px] text-gray-400">
                        Turning a courier away wastes a whole trip. They can still buy — just not
                        this way.
                    </p>
                    <InputError message={form.errors.max_refusals} className="mt-1" />
                </label>

                <div>
                    <span className="mb-1 block text-xs font-bold text-gray-700">
                        States it is offered in
                    </span>
                    <p className="mb-2 text-[11px] text-gray-400">
                        {form.data.states.length === 0
                            ? 'None picked — offered everywhere.'
                            : `${form.data.states.length} picked. Everywhere else pays upfront.`}
                    </p>
                    <div className="max-h-44 space-y-0.5 overflow-y-auto rounded-xl border border-gray-100 p-2">
                        {allStates.map((state) => (
                            <label
                                key={state}
                                className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-xs hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    checked={form.data.states.includes(state)}
                                    onChange={() => toggleState(state)}
                                    className="rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                                />
                                {state}
                            </label>
                        ))}
                    </div>
                </div>

                {form.data.enabled && (
                    <p className="rounded-xl bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-800">
                        <strong>While this is on</strong>, couriers carry cash. Give each of them a
                        float ceiling on the Staff screen, and confirm hand-ins the same day.
                    </p>
                )}

                <button
                    type="submit"
                    disabled={form.processing}
                    className="w-full rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 disabled:opacity-50"
                >
                    Save settings
                </button>
            </form>
        </Card>
    );
}
