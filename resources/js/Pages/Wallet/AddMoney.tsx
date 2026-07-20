import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CreditCard, Landmark, Smartphone } from 'lucide-react';
import { FormEventHandler } from 'react';

const QUICK_AMOUNTS = [2000, 5000, 10000, 20000, 50000, 100000];

export default function AddMoney() {
    const form = useForm({ amount_naira: '' as number | '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('wallet.deposit'));
    };

    return (
        <AccountLayout title="Add money">
            <Head title="Add money" />

            <Link
                href={route('wallet.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Back to wallet
            </Link>

            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">Add money</h1>
            <p className="mt-1 text-sm text-gray-500">
                Fund your wallet securely through Paystack. Your balance updates the moment payment is confirmed.
            </p>

            <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_300px]">
                <Card>
                    <form onSubmit={submit}>
                        <label htmlFor="amount" className="text-sm font-semibold text-gray-900">
                            Amount (₦)
                        </label>
                        <div className="mt-2 flex items-center rounded-2xl border border-gray-200 px-4 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/15">
                            <span className="text-xl font-bold text-gray-400">₦</span>
                            <input
                                id="amount"
                                type="number"
                                min="100"
                                step="1"
                                inputMode="numeric"
                                autoFocus
                                value={form.data.amount_naira}
                                onChange={(e) =>
                                    form.setData('amount_naira', e.target.value === '' ? '' : Number(e.target.value))
                                }
                                placeholder="0"
                                className="w-full border-0 bg-transparent px-2 py-3 text-2xl font-extrabold text-gray-900 focus:outline-none focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
                            />
                        </div>
                        <InputError message={form.errors.amount_naira} className="mt-2" />

                        <div className="mt-4 grid grid-cols-3 gap-2">
                            {QUICK_AMOUNTS.map((amount) => (
                                <button
                                    key={amount}
                                    type="button"
                                    onClick={() => form.setData('amount_naira', amount)}
                                    className={`rounded-xl border py-2.5 text-sm font-semibold transition active:scale-95 ${
                                        form.data.amount_naira === amount
                                            ? 'border-brand-600 bg-brand-50 text-brand-700'
                                            : 'border-gray-200 text-gray-700 hover:border-brand-300'
                                    }`}
                                >
                                    ₦{amount.toLocaleString()}
                                </button>
                            ))}
                        </div>

                        <Button
                            type="submit"
                            disabled={form.processing || form.data.amount_naira === '' || Number(form.data.amount_naira) < 100}
                            className="mt-5 w-full active:scale-[0.98]"
                        >
                            {form.processing ? 'Redirecting to Paystack…' : 'Continue to secure payment'}
                        </Button>
                        <p className="mt-3 text-center text-xs text-gray-400">
                            You'll be redirected to Paystack to complete payment. No cash withdrawal — deposits only.
                        </p>
                    </form>
                </Card>

                {/* Channels / reassurance */}
                <div className="space-y-3">
                    <Card>
                        <p className="text-xs font-bold uppercase tracking-wide text-gray-400">Ways to pay</p>
                        <ul className="mt-3 space-y-3 text-sm">
                            <li className="flex items-center gap-3 text-gray-700">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                                    <CreditCard className="h-4 w-4" />
                                </span>
                                Debit / credit card
                            </li>
                            <li className="flex items-center gap-3 text-gray-700">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                                    <Landmark className="h-4 w-4" />
                                </span>
                                Bank transfer
                            </li>
                            <li className="flex items-center gap-3 text-gray-700">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                                    <Smartphone className="h-4 w-4" />
                                </span>
                                USSD
                            </li>
                        </ul>
                    </Card>
                    <p className="px-1 text-xs leading-relaxed text-gray-400">
                        🔒 Payments are processed by Paystack. FirstMarket never stores your full card details.
                    </p>
                </div>
            </div>
        </AccountLayout>
    );
}
