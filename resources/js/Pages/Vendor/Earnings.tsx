import ViewToggle from '@/Components/ui/ViewToggle';
import { useViewMode } from '@/Hooks/useViewMode';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Select } from '@/Components/ui/Select';
import VendorLayout from '@/Layouts/VendorLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, Banknote, CheckCircle2, Clock, Landmark, ShieldCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface LedgerRow {
    uuid: string;
    type: string;
    amountKobo: number;
    balanceAfterKobo: number;
    note: string | null;
    at: string | null;
}

interface PayoutRow {
    id: number;
    amountKobo: number;
    status: string;
    reference: string | null;
    failureReason: string | null;
    paidAt: string | null;
    createdAt: string;
}

interface Props {
    clearedBalanceKobo: number;
    pendingKobo: number;
    ledger: LedgerRow[];
    payouts: PayoutRow[];
    bankAccount: {
        bankName: string | null;
        bankCode: string;
        accountName: string;
        accountNumberMasked: string;
        verified: boolean;
    } | null;
    [key: string]: unknown;
}

/** Common Nigerian banks for the payout form (Paystack bank codes). */
const BANKS = [
    { code: '044', name: 'Access Bank' },
    { code: '058', name: 'GTBank' },
    { code: '057', name: 'Zenith Bank' },
    { code: '011', name: 'First Bank' },
    { code: '033', name: 'UBA' },
    { code: '070', name: 'Fidelity Bank' },
    { code: '032', name: 'Union Bank' },
    { code: '232', name: 'Sterling Bank' },
    { code: '050', name: 'Ecobank' },
    { code: '221', name: 'Stanbic IBTC' },
    { code: '035', name: 'Wema Bank' },
    { code: '214', name: 'FCMB' },
    { code: '301', name: 'Jaiz Bank' },
    { code: '076', name: 'Polaris Bank' },
    { code: '082', name: 'Keystone Bank' },
];

const payoutStatusStyle: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-600',
    approved: 'bg-sky-50 text-sky-700',
    paid: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-red-50 text-red-700',
    rejected: 'bg-red-50 text-red-700',
};

export default function VendorEarnings() {
    const { clearedBalanceKobo, pendingKobo, ledger, payouts, bankAccount } = usePage<Props>().props;

    const [editingBank, setEditingBank] = useState(bankAccount === null);
    const bankForm = useForm({ bank_code: '', bank_name: '', account_number: '' });

    const submitBank: FormEventHandler = (e) => {
        e.preventDefault();
        bankForm.post(route('vendor.earnings.bank-account'), {
            preserveScroll: true,
            onSuccess: () => {
                bankForm.reset();
                setEditingBank(false);
            },
        });
    };

    // Read-only, so a toggle but no checkboxes — there is nothing to act on.
    const { mode, choose } = useViewMode('vendor.earnings', 'table');

    return (
        <VendorLayout>
            <Head title="Earnings" />

            <h1 className="text-2xl font-extrabold tracking-tight text-gray-900">Earnings</h1>
            <p className="mt-1 text-sm text-gray-500">
                Cleared earnings are paid to your verified bank account in the weekly payout run.
            </p>

            {/* ── Balance tiles ── */}
            <div className="mt-6 grid gap-4 sm:grid-cols-2">
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-900 p-5 text-white shadow-lg">
                    <Banknote className="pointer-events-none absolute -right-3 -top-4 h-24 w-24 opacity-10" aria-hidden="true" />
                    <p className="text-xs font-bold uppercase tracking-[0.18em] text-brand-100">Cleared balance</p>
                    <p className="mt-1.5 text-3xl font-extrabold tracking-tight">
                        {formatNairaFromKobo(clearedBalanceKobo)}
                    </p>
                    <p className="mt-2 text-xs text-brand-100">Delivered + confirmed orders, minus payouts.</p>
                </div>
                <Card className="flex flex-col justify-center">
                    <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-gray-400">
                        <Clock className="h-4 w-4" /> Pending (in delivery)
                    </p>
                    <p className="mt-1.5 text-3xl font-extrabold tracking-tight text-gray-900">
                        {formatNairaFromKobo(pendingKobo)}
                    </p>
                    <p className="mt-2 text-xs text-gray-400">
                        Clears when the customer confirms delivery (or the confirmation window closes).
                    </p>
                </Card>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_340px]">
                <div className="space-y-4">
                    {/* ── Ledger ── */}
                    <Card className="p-0">
                        <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
                            <h2 className="text-sm font-bold text-gray-900">Earnings ledger</h2>
                            <ViewToggle mode={mode} onChange={choose} label="ledger" />
                        </div>
                        {ledger.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-gray-400">
                                Confirmed deliveries appear here with your per-order earnings.
                            </p>
                        ) : mode === 'table' ? (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[560px] text-sm">
                                    <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th className="w-12 py-3 pl-5 pr-2 font-semibold">S/N</th>
                                            <th className="px-4 py-3 font-semibold">Entry</th>
                                            <th className="px-4 py-3 font-semibold">When</th>
                                            <th className="px-4 py-3 text-right font-semibold">Amount</th>
                                            <th className="px-5 py-3 text-right font-semibold">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {ledger.map((row, index) => {
                                            const credit = row.amountKobo > 0;

                                            return (
                                                <tr key={row.uuid} className="transition-colors hover:bg-slate-50/60">
                                                    <td className="py-3 pl-5 pr-2 text-xs tabular-nums text-gray-400">
                                                        {index + 1}
                                                    </td>
                                                    <td className="px-4 py-3 text-gray-900">
                                                        {row.note ?? row.type}
                                                    </td>
                                                    <td className="px-4 py-3 text-xs text-gray-500">{row.at}</td>
                                                    <td
                                                        className={cn(
                                                            'px-4 py-3 text-right font-bold tabular-nums',
                                                            credit ? 'text-emerald-600' : 'text-gray-700',
                                                        )}
                                                    >
                                                        {credit ? '+' : '−'}{' '}
                                                        {formatNairaFromKobo(Math.abs(row.amountKobo))}
                                                    </td>
                                                    <td className="px-5 py-3 text-right tabular-nums text-gray-500">
                                                        {formatNairaFromKobo(row.balanceAfterKobo)}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {ledger.map((row) => {
                                    const credit = row.amountKobo > 0;
                                    return (
                                        <li key={row.uuid} className="flex items-center gap-3 px-5 py-3.5">
                                            <span
                                                className={cn(
                                                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl',
                                                    credit
                                                        ? 'bg-emerald-50 text-emerald-600'
                                                        : 'bg-gray-100 text-gray-500',
                                                )}
                                            >
                                                {credit ? (
                                                    <ArrowDownLeft className="h-4 w-4" />
                                                ) : (
                                                    <ArrowUpRight className="h-4 w-4" />
                                                )}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium text-gray-900">
                                                    {row.note ?? row.type}
                                                </p>
                                                <p className="text-xs text-gray-400">
                                                    {row.at} · bal {formatNairaFromKobo(row.balanceAfterKobo)}
                                                </p>
                                            </div>
                                            <p
                                                className={cn(
                                                    'text-sm font-bold',
                                                    credit ? 'text-emerald-600' : 'text-gray-700',
                                                )}
                                            >
                                                {credit ? '+' : '−'} {formatNairaFromKobo(Math.abs(row.amountKobo))}
                                            </p>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </Card>

                    {/* ── Payout history ── */}
                    <Card className="p-0">
                        <h2 className="border-b border-gray-100 px-5 py-4 text-sm font-bold text-gray-900">
                            Payout history
                        </h2>
                        {payouts.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-gray-400">
                                Weekly payouts of your cleared balance show here.
                            </p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {payouts.map((payout) => (
                                    <li key={payout.id} className="flex items-center gap-3 px-5 py-3.5">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium text-gray-900">
                                                {formatNairaFromKobo(payout.amountKobo)}
                                            </p>
                                            <p className="text-xs text-gray-400">
                                                {payout.paidAt ?? payout.createdAt}
                                                {payout.reference && ` · ${payout.reference}`}
                                                {payout.failureReason && ` · ${payout.failureReason}`}
                                            </p>
                                        </div>
                                        <span
                                            className={cn(
                                                'rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase',
                                                payoutStatusStyle[payout.status] ?? 'bg-gray-100 text-gray-500',
                                            )}
                                        >
                                            {payout.status}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                {/* ── Bank account ── */}
                <Card className="self-start">
                    <h2 className="flex items-center gap-2 text-sm font-bold text-gray-900">
                        <Landmark className="h-4 w-4 text-brand-600" /> Payout bank account
                    </h2>

                    {bankAccount && !editingBank ? (
                        <div className="mt-3">
                            <div className="rounded-xl border border-gray-200 p-4">
                                <p className="text-sm font-bold text-gray-900">{bankAccount.accountName}</p>
                                <p className="mt-0.5 text-xs text-gray-500">
                                    {bankAccount.bankName} · {bankAccount.accountNumberMasked}
                                </p>
                                {bankAccount.verified && (
                                    <p className="mt-1.5 flex items-center gap-1 text-xs font-semibold text-emerald-600">
                                        <CheckCircle2 className="h-3.5 w-3.5" /> Verified for payouts
                                    </p>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={() => setEditingBank(true)}
                                className="mt-3 text-sm font-semibold text-brand-600 hover:underline"
                            >
                                Change account
                            </button>
                        </div>
                    ) : (
                        <form onSubmit={submitBank} className="mt-3 space-y-3">
                            <div>
                                <Select
                                    value={bankForm.data.bank_code}
                                    onChange={(e) => {
                                        const bank = BANKS.find((b) => b.code === e.target.value);
                                        bankForm.setData((data) => ({
                                            ...data,
                                            bank_code: e.target.value,
                                            bank_name: bank?.name ?? '',
                                        }));
                                    }}
                                    required
                                >
                                    <option value="">Select your bank</option>
                                    {BANKS.map((bank) => (
                                        <option key={bank.code} value={bank.code}>
                                            {bank.name}
                                        </option>
                                    ))}
                                </Select>
                                <InputError message={bankForm.errors.bank_code} className="mt-1" />
                            </div>
                            <div>
                                <Input
                                    type="text"
                                    inputMode="numeric"
                                    maxLength={10}
                                    placeholder="Account number (10 digits)"
                                    value={bankForm.data.account_number}
                                    onChange={(e) => bankForm.setData('account_number', e.target.value)}
                                    required
                                    className="rounded-xl"
                                />
                                <InputError message={bankForm.errors.account_number} className="mt-1" />
                            </div>
                            <button
                                type="submit"
                                disabled={bankForm.processing}
                                className="w-full rounded-full bg-brand-600 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-[0.98] disabled:opacity-60"
                            >
                                {bankForm.processing ? 'Verifying account…' : 'Verify and save'}
                            </button>
                            {bankAccount && (
                                <button
                                    type="button"
                                    onClick={() => setEditingBank(false)}
                                    className="w-full text-center text-sm font-medium text-gray-500 hover:text-gray-700"
                                >
                                    Cancel
                                </button>
                            )}
                        </form>
                    )}

                    <p className="mt-4 flex items-start gap-1.5 text-xs leading-relaxed text-gray-400">
                        <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        The account name is verified with your bank before saving. Payouts only ever go to a
                        verified account in your business name.
                    </p>
                </Card>
            </div>
        </VendorLayout>
    );
}
