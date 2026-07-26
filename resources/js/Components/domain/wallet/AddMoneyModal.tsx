import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import { useForm } from '@inertiajs/react';
import { CreditCard, Landmark, Smartphone, Wallet as WalletIcon } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

const QUICK_AMOUNTS = [2000, 5000, 10000, 20000, 50000, 100000];

/** Wallet top-up, opened as a modal from the wallet page instead of a dedicated route. */
export default function AddMoneyModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    const form = useForm({ amount_naira: '' as number | '' });

    useEffect(() => {
        if (open) form.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('wallet.deposit'));
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            size="md"
            icon={<WalletIcon className="h-5 w-5" />}
            title="Add money"
            description="Fund your wallet securely through Paystack. Your balance updates the moment payment is confirmed."
        >
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
                        onChange={(e) => form.setData('amount_naira', e.target.value === '' ? '' : Number(e.target.value))}
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

                <div className="mt-5 grid grid-cols-3 gap-2 border-t border-gray-100 pt-4 text-center">
                    <div className="flex flex-col items-center gap-1.5">
                        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                            <CreditCard className="h-4 w-4" />
                        </span>
                        <span className="text-[11px] font-medium text-gray-600">Card</span>
                    </div>
                    <div className="flex flex-col items-center gap-1.5">
                        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <Landmark className="h-4 w-4" />
                        </span>
                        <span className="text-[11px] font-medium text-gray-600">Bank transfer</span>
                    </div>
                    <div className="flex flex-col items-center gap-1.5">
                        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                            <Smartphone className="h-4 w-4" />
                        </span>
                        <span className="text-[11px] font-medium text-gray-600">USSD</span>
                    </div>
                </div>
                <p className="mt-3 text-center text-xs text-gray-400">
                    🔒 Payments are processed by Paystack. FirstMaket never stores your full card details.
                </p>
            </form>
        </Modal>
    );
}
