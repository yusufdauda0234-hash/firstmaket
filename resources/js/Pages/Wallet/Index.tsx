import AddMoneyModal from '@/Components/domain/wallet/AddMoneyModal';
import { Card } from '@/Components/ui/Card';
import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, Plus, Receipt, ShieldCheck, Wallet as WalletIcon } from 'lucide-react';
import { useState } from 'react';

interface TransactionRow {
    uuid: string;
    type: string;
    direction: string;
    amountKobo: number;
    balanceAfterKobo: number;
    reference: string;
    receiptNumber: string | null;
    createdAt: string;
}

interface Props {
    wallet: { balanceKobo: number; currency: string; status: string };
    recentTransactions: TransactionRow[];
    openAddMoney: boolean;
    [key: string]: unknown;
}

export const txnLabel: Record<string, string> = {
    deposit: 'Wallet top-up',
    plan_contribution: 'Plan contribution',
    open_savings_allocation: 'Moved to Open Savings',
    redirection: 'Plan redirection',
    refund_to_plan_only: 'Refund to plan',
    adjustment: 'Adjustment',
};

export default function WalletIndex({ wallet, recentTransactions, openAddMoney }: Props) {
    const [showAddMoney, setShowAddMoney] = useState(openAddMoney);

    return (
        <AccountLayout title="My Wallet">
            <Head title="Wallet" />

            <AddMoneyModal open={showAddMoney} onClose={() => setShowAddMoney(false)} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">Wallet</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Fund your wallet to pay at once or save toward a product.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setShowAddMoney(true)}
                    className="flex items-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-brand-700 hover:shadow-lg active:scale-95"
                >
                    <Plus className="h-4 w-4" /> Add money
                </button>
            </div>

            {/* Balance card */}
            <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-900 p-6 text-white shadow-lg sm:p-8">
                <span className="pointer-events-none absolute -right-6 -top-8 select-none text-[9rem] leading-none opacity-10" aria-hidden="true">
                    ₦
                </span>
                <div className="relative z-[1] flex items-center gap-2 text-brand-100">
                    <WalletIcon className="h-4 w-4" />
                    <span className="text-xs font-bold uppercase tracking-[0.18em]">Available balance</span>
                </div>
                <p className="relative z-[1] mt-2 text-4xl font-extrabold tracking-tight sm:text-5xl">
                    {formatNairaFromKobo(wallet.balanceKobo)}
                </p>
                <p className="relative z-[1] mt-3 flex items-center gap-1.5 text-xs text-brand-100">
                    <ShieldCheck className="h-3.5 w-3.5" /> Deposit-only wallet · no cash withdrawal · secured by Paystack
                </p>
            </div>

            {/* Recent activity */}
            <Card className="mt-6 overflow-hidden p-0">
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h2 className="font-bold text-gray-900">Recent activity</h2>
                    <Link href={route('wallet.transactions')} className="text-sm font-semibold text-brand-600 hover:underline">
                        View all →
                    </Link>
                </div>

                {recentTransactions.length === 0 ? (
                    <div className="flex flex-col items-center px-6 py-14 text-center">
                        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                            <WalletIcon className="h-7 w-7" />
                        </span>
                        <p className="mt-4 text-sm font-medium text-gray-900">No transactions yet</p>
                        <p className="mt-1 text-sm text-gray-500">
                            Add money to get started — it reflects the moment your payment is confirmed.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {recentTransactions.map((txn) => {
                            const credit = txn.direction === 'credit';
                            return (
                            <li key={txn.uuid} className="flex items-center gap-4 px-5 py-4">
                                <span
                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
                                        credit ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500'
                                    }`}
                                >
                                    {credit ? <ArrowDownLeft className="h-5 w-5" /> : <ArrowUpRight className="h-5 w-5" />}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium text-gray-900">
                                        {txnLabel[txn.type] ?? txn.type}
                                    </p>
                                    <p className="text-xs text-gray-400">{txn.createdAt}</p>
                                </div>
                                <div className="text-right">
                                    <p className={`font-bold ${credit ? 'text-emerald-600' : 'text-gray-700'}`}>
                                        {credit ? '+' : '−'} {formatNairaFromKobo(txn.amountKobo)}
                                    </p>
                                    {txn.receiptNumber && (
                                        <Link
                                            href={route('wallet.receipt', txn.uuid)}
                                            className="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline"
                                        >
                                            <Receipt className="h-3 w-3" /> Receipt
                                        </Link>
                                    )}
                                </div>
                            </li>
                            );
                        })}
                    </ul>
                )}
            </Card>
        </AccountLayout>
    );
}
