import { Card } from '@/Components/ui/Card';
import { Pagination } from '@/Components/ui/Pagination';
import { txnLabel } from '@/Pages/Wallet/Index';
import AccountLayout from '@/Layouts/AccountLayout';
import { Paginated } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownLeft, ArrowLeft, ArrowUpRight, Receipt } from 'lucide-react';

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
    transactions: Paginated<TransactionRow>;
    filters: { type: string | null; from: string | null; to: string | null };
    [key: string]: unknown;
}

const TYPE_TABS = [
    { value: '', label: 'All' },
    { value: 'deposit', label: 'Top-ups' },
    { value: 'plan_contribution', label: 'Plan contributions' },
    { value: 'open_savings_allocation', label: 'Open Savings' },
    { value: 'redirection', label: 'Redirections' },
];

export default function WalletTransactions({ transactions, filters }: Props) {
    const apply = (type: string) =>
        router.get(route('wallet.transactions'), type ? { type } : {}, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <AccountLayout title="Transactions">
            <Head title="Transactions" />

            <Link
                href={route('wallet.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Back to wallet
            </Link>

            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">Transactions</h1>
            <p className="mt-1 text-sm text-gray-500">Every movement in and out of your wallet.</p>

            <div className="mt-4 flex flex-wrap gap-2">
                {TYPE_TABS.map((tab) => (
                    <button
                        key={tab.value}
                        type="button"
                        onClick={() => apply(tab.value)}
                        className={
                            (filters.type ?? '') === tab.value
                                ? 'rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm'
                                : 'rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-600 transition hover:border-brand-300 hover:text-brand-700'
                        }
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            <Card className="mt-4 overflow-hidden p-0">
                {transactions.data.length === 0 ? (
                    <p className="px-6 py-14 text-center text-sm text-gray-500">
                        No transactions match this filter.
                    </p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {transactions.data.map((txn) => {
                            const credit = txn.direction === 'credit';
                            return (
                                <li key={txn.uuid} className="flex items-center gap-4 px-5 py-4">
                                    <span
                                        className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
                                            credit ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500'
                                        }`}
                                    >
                                        {credit ? (
                                            <ArrowDownLeft className="h-5 w-5" />
                                        ) : (
                                            <ArrowUpRight className="h-5 w-5" />
                                        )}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium text-gray-900">
                                            {txnLabel[txn.type] ?? txn.type}
                                        </p>
                                        <p className="text-xs text-gray-400">
                                            {txn.createdAt} · Bal {formatNairaFromKobo(txn.balanceAfterKobo)}
                                        </p>
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

            <Pagination links={transactions.links} />
        </AccountLayout>
    );
}
