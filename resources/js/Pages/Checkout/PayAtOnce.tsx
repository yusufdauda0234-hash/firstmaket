import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Lock, PackageCheck, Plus, ShieldCheck, Truck, Wallet, Zap } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    product: {
        uuid: string;
        name: string;
        slug: string;
        priceKobo: number;
        image: string | null;
        vendorName: string | null;
    };
    walletBalanceKobo: number;
    [key: string]: unknown;
}

/**
 * Pay At Once checkout: pay the full locked price from the wallet in one
 * step. Wallet money is webhook-verified Paystack money, so this never
 * touches a card directly — if the balance is short, the customer tops up
 * through the normal Add Money flow first.
 */
export default function PayAtOnce() {
    const { product, walletBalanceKobo } = usePage<Props>().props;

    const form = useForm({});

    const canAfford = walletBalanceKobo >= product.priceKobo;
    const shortfallKobo = Math.max(0, product.priceKobo - walletBalanceKobo);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('checkout.pay-at-once.store', product.slug));
    };

    return (
        <AccountLayout title="Pay At Once">
            <Head title={`Checkout — ${product.name}`} />

            <Link
                href={route('catalog.product', product.slug)}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Back to product
            </Link>

            <h1 className="flex items-center gap-2 text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">
                <Zap className="h-6 w-6 text-brand-yellow" /> Pay At Once
            </h1>
            <p className="mt-1 text-sm text-gray-500">
                Pay the full price now from your wallet and your order goes straight to delivery preparation.
            </p>

            <div className="mt-5 grid gap-4 lg:grid-cols-[1fr_320px]">
                {/* ── Order summary ── */}
                <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Order summary</h2>
                    <div className="mt-3 flex items-center gap-4">
                        <span className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                            {product.image ? (
                                <img src={product.image} alt="" className="h-full w-full object-cover" />
                            ) : (
                                <PackageCheck className="h-8 w-8 text-gray-300" />
                            )}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-bold text-gray-900">{product.name}</p>
                            {product.vendorName && (
                                <p className="mt-0.5 flex items-center gap-1 text-xs text-emerald-600">
                                    <ShieldCheck className="h-3.5 w-3.5" /> {product.vendorName}
                                </p>
                            )}
                            <p className="mt-1 flex items-center gap-1.5 text-xs font-semibold text-brand-700">
                                <Lock className="h-3.5 w-3.5" /> Price locked at checkout
                            </p>
                        </div>
                    </div>

                    <dl className="mt-4 space-y-2 border-t border-gray-100 pt-4 text-sm">
                        <div className="flex items-center justify-between">
                            <dt className="text-gray-500">Product price</dt>
                            <dd className="font-semibold text-gray-900">{formatNairaFromKobo(product.priceKobo)}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                            <dt className="text-gray-500">Delivery</dt>
                            <dd className="flex items-center gap-1 text-xs font-semibold text-emerald-600">
                                <Truck className="h-3.5 w-3.5" /> FirstMarket delivers
                            </dd>
                        </div>
                        <div className="flex items-center justify-between border-t border-gray-100 pt-2 text-base">
                            <dt className="font-bold text-gray-900">Total</dt>
                            <dd className="font-extrabold text-brand-700">{formatNairaFromKobo(product.priceKobo)}</dd>
                        </div>
                    </dl>
                </div>

                {/* ── Payment ── */}
                <div className="space-y-3">
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Pay from wallet</h2>
                        <p className="mt-3 flex items-center gap-2 text-sm text-gray-600">
                            <Wallet className="h-4 w-4 text-brand-600" />
                            Balance:{' '}
                            <span className={canAfford ? 'font-bold text-gray-900' : 'font-bold text-red-600'}>
                                {formatNairaFromKobo(walletBalanceKobo)}
                            </span>
                        </p>

                        {canAfford ? (
                            <form onSubmit={submit}>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="mt-4 flex w-full items-center justify-center gap-2 rounded-full bg-brand-600 py-3 text-sm font-bold text-white transition hover:bg-brand-700 hover:shadow-lg hover:shadow-brand-600/30 active:scale-[0.98] disabled:opacity-60"
                                >
                                    {form.processing
                                        ? 'Processing…'
                                        : `Pay ${formatNairaFromKobo(product.priceKobo)} now`}
                                </button>
                                <InputError message={(form.errors as Record<string, string>).amount} className="mt-2" />
                                <InputError message={(form.errors as Record<string, string>).product} className="mt-2" />
                                <InputError message={(form.errors as Record<string, string>).wallet} className="mt-2" />
                            </form>
                        ) : (
                            <>
                                <p className="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                    You need {formatNairaFromKobo(shortfallKobo)} more to pay at once.
                                </p>
                                <Link
                                    href={route('wallet.add-money')}
                                    className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-full bg-brand-600 py-3 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-[0.98]"
                                >
                                    <Plus className="h-4 w-4" /> Add money to wallet
                                </Link>
                                <Link
                                    href={route('savings.plans.start', product.slug)}
                                    className="mt-2 block text-center text-sm font-semibold text-brand-600 hover:underline"
                                >
                                    Or save toward it small small →
                                </Link>
                            </>
                        )}
                    </div>

                    <p className="px-1 text-xs leading-relaxed text-gray-400">
                        🔒 Wallet money is verified by Paystack before it can be spent. Once paid, your order moves
                        straight to Ready for Delivery.
                    </p>
                </div>
            </div>
        </AccountLayout>
    );
}
