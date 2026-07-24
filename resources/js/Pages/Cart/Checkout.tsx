import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ShoppingBag, Wallet } from 'lucide-react';
import { FormEventHandler } from 'react';

interface CheckoutItem {
    id: number;
    productName: string;
    productImage: string | null;
    vendorName: string;
    quantity: number;
    lineTotalKobo: number;
}

interface Props {
    items: CheckoutItem[];
    totalKobo: number;
    walletBalanceKobo: number;
    [key: string]: unknown;
}

export default function CartCheckout() {
    const { items, totalKobo, walletBalanceKobo } = usePage<Props>().props;

    const form = useForm({ delivery_address: '', state: '', lga: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('cart.checkout.store'));
    };

    const insufficientFunds = walletBalanceKobo < totalKobo;

    return (
        <AccountLayout title="Checkout">
            <Head title="Checkout" />

            <Link
                href={route('cart.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Back to cart
            </Link>

            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">Pay in full</h1>
            <p className="mt-1 text-sm text-gray-500">
                One wallet debit for everything below. Each vendor is notified the moment payment goes through.
            </p>

            <Card className="mt-5 p-0">
                <ul className="divide-y divide-gray-100">
                    {items.map((item) => (
                        <li key={item.id} className="flex items-center gap-4 px-5 py-3.5">
                            <span className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                {item.productImage ? (
                                    <img src={item.productImage} alt="" className="h-full w-full object-cover" />
                                ) : (
                                    <ShoppingBag className="h-5 w-5 text-gray-300" />
                                )}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-semibold text-gray-900">{item.productName}</p>
                                <p className="text-xs text-gray-400">
                                    {item.vendorName} · Qty {item.quantity}
                                </p>
                            </div>
                            <p className="text-sm font-bold text-gray-900">{formatNairaFromKobo(item.lineTotalKobo)}</p>
                        </li>
                    ))}
                </ul>
                <div className="flex items-center justify-between border-t border-gray-100 px-5 py-4">
                    <span className="text-sm font-semibold text-gray-900">Total</span>
                    <span className="text-lg font-extrabold text-gray-900">{formatNairaFromKobo(totalKobo)}</span>
                </div>
            </Card>

            <form onSubmit={submit} className="mt-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 className="text-sm font-bold text-gray-900">Delivery address</h2>
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <input
                            type="text"
                            placeholder="Street address (house number, street, area)"
                            value={form.data.delivery_address}
                            onChange={(e) => form.setData('delivery_address', e.target.value)}
                            required
                            className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                        />
                        <InputError message={form.errors.delivery_address} className="mt-1" />
                    </div>
                    <div>
                        <input
                            type="text"
                            placeholder="State (e.g. Lagos)"
                            value={form.data.state}
                            onChange={(e) => form.setData('state', e.target.value)}
                            required
                            className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                        />
                        <InputError message={form.errors.state} className="mt-1" />
                    </div>
                    <div>
                        <input
                            type="text"
                            placeholder="LGA"
                            value={form.data.lga}
                            onChange={(e) => form.setData('lga', e.target.value)}
                            required
                            className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                        />
                        <InputError message={form.errors.lga} className="mt-1" />
                    </div>
                </div>

                <p className="mt-4 flex items-center gap-1.5 text-xs text-gray-500">
                    <Wallet className="h-3.5 w-3.5" /> Wallet balance: {formatNairaFromKobo(walletBalanceKobo)}
                    {insufficientFunds && (
                        <span className="font-semibold text-red-600">
                            {' '}
                            — add {formatNairaFromKobo(totalKobo - walletBalanceKobo)} more to pay in full.
                        </span>
                    )}
                </p>
                <InputError message={(form.errors as Record<string, string>).cart} className="mt-1" />

                <Button type="submit" disabled={form.processing || insufficientFunds} className="mt-4 w-full active:scale-[0.98]">
                    {form.processing ? 'Placing order…' : `Pay ${formatNairaFromKobo(totalKobo)} now`}
                </Button>

                {insufficientFunds && (
                    <Link
                        href={route('wallet.index')}
                        className="mt-3 block text-center text-sm font-bold text-brand-600 hover:underline"
                    >
                        Add money to wallet →
                    </Link>
                )}
            </form>
        </AccountLayout>
    );
}
