import { Card } from '@/Components/ui/Card';
import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, CreditCard, Gift, Receipt } from 'lucide-react';

interface PaymentRow {
    uuid: string;
    amountKobo: number;
    paidAfterKobo: number;
    source: string;
    reference: string | null;
    at: string | null;
}

interface Props {
    goal: {
        uuid: string;
        title: string;
        targetKobo: number;
        paidKobo: number;
        remainingKobo: number;
        paymentsMade: number;
        installments: number;
    };
    payments: {
        data: PaymentRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        total: number;
    };
    [key: string]: unknown;
}

/**
 * Every payment into one plan.
 *
 * Its own page rather than a list on the plan screen: a plan runs for months,
 * so the history outgrows the space it had there, and this is what a customer
 * reaches for when checking their plan against their bank statement — which
 * is why each row carries its reference.
 */
export default function PlanPayments() {
    const { goal, payments } = usePage<Props>().props;
    const firstIndex = (payments.from ?? 1) - 1;

    return (
        <AccountLayout title="Payment history">
            <Head title={`Payments — ${goal.title}`} />

            <Link
                href={route('savings.goals.show', goal.uuid)}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 transition hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Back to the plan
            </Link>

            <div className="mb-5">
                <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">
                    Payment history
                </h1>
                <p className="mt-1 text-sm text-gray-500">{goal.title}</p>
            </div>

            {/* ── Where the plan stands ── */}
            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <Card>
                    <p className="text-xs font-bold uppercase tracking-wide text-gray-400">Paid so far</p>
                    <p className="mt-1 text-2xl font-extrabold tracking-tight text-gray-900">
                        {formatNairaFromKobo(goal.paidKobo)}
                    </p>
                    <p className="mt-1 text-xs text-gray-400">
                        {goal.paymentsMade} payment{goal.paymentsMade === 1 ? '' : 's'} of{' '}
                        {goal.installments}
                    </p>
                </Card>
                <Card>
                    <p className="text-xs font-bold uppercase tracking-wide text-gray-400">Still to go</p>
                    <p className="mt-1 text-2xl font-extrabold tracking-tight text-gray-900">
                        {formatNairaFromKobo(goal.remainingKobo)}
                    </p>
                    <p className="mt-1 text-xs text-gray-400">
                        of {formatNairaFromKobo(goal.targetKobo)} locked
                    </p>
                </Card>
                <Card>
                    <p className="text-xs font-bold uppercase tracking-wide text-gray-400">Payments listed</p>
                    <p className="mt-1 text-2xl font-extrabold tracking-tight text-gray-900">
                        {payments.total}
                    </p>
                    <p className="mt-1 text-xs text-gray-400">Newest first</p>
                </Card>
            </div>

            {payments.data.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Receipt className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No payments yet</p>
                    <p className="mt-1 max-w-sm text-sm text-gray-500">
                        Every payment into this plan will be listed here with its reference.
                    </p>
                </Card>
            ) : (
                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[680px] text-sm">
                            <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="w-12 py-3 pl-5 pr-2 font-semibold">S/N</th>
                                    <th className="px-4 py-3 font-semibold">How</th>
                                    <th className="px-4 py-3 font-semibold">When</th>
                                    <th className="px-4 py-3 font-semibold">Reference</th>
                                    <th className="px-4 py-3 text-right font-semibold">Amount</th>
                                    <th className="px-5 py-3 text-right font-semibold">Paid to date</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {payments.data.map((payment, index) => (
                                    <tr key={payment.uuid} className="transition-colors hover:bg-slate-50/60">
                                        <td className="py-3 pl-5 pr-2 text-xs tabular-nums text-gray-400">
                                            {firstIndex + index + 1}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                    payment.source === 'credit'
                                                        ? 'bg-emerald-50 text-emerald-700'
                                                        : 'bg-slate-100 text-slate-600'
                                                }`}
                                            >
                                                {payment.source === 'credit' ? (
                                                    <>
                                                        <Gift className="h-3 w-3" /> Credit
                                                    </>
                                                ) : (
                                                    <>
                                                        <CreditCard className="h-3 w-3" /> Card
                                                    </>
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">{payment.at}</td>
                                        {/* The figure a customer quotes to support, or
                                            matches against their bank statement. */}
                                        <td className="px-4 py-3 font-mono text-[11px] text-gray-400">
                                            {payment.reference ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right font-bold tabular-nums text-emerald-700">
                                            +{formatNairaFromKobo(payment.amountKobo)}
                                        </td>
                                        <td className="px-5 py-3 text-right tabular-nums text-gray-600">
                                            {formatNairaFromKobo(payment.paidAfterKobo)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {payments.links.length > 3 && (
                        <div className="flex flex-wrap gap-1.5 border-t border-gray-100 px-5 py-3">
                            {payments.links.map((link) => (
                                <Link
                                    key={link.label}
                                    href={link.url ?? '#'}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                        link.active
                                            ? 'bg-brand-600 text-white'
                                            : link.url
                                              ? 'text-gray-600 hover:bg-gray-100'
                                              : 'cursor-not-allowed text-gray-300'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </Card>
            )}
        </AccountLayout>
    );
}
