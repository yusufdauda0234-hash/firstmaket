import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, Lock, PackageCheck, Zap } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    product: {
        uuid: string;
        name: string;
        slug: string;
        priceKobo: number;
        image: string | null;
    };
    walletBalanceKobo: number;
    openSavingsBalanceKobo: number;
    [key: string]: unknown;
}

const CADENCES = [
    { value: 'daily', label: 'Daily', hint: 'Small amounts, fastest habit' },
    { value: 'weekly', label: 'Weekly', hint: 'Most popular pace' },
    { value: 'monthly', label: 'Monthly', hint: 'Salary-friendly' },
] as const;

export default function StartPlan() {
    const { product, walletBalanceKobo } = usePage<Props>().props;

    const form = useForm({
        product_uuid: product.uuid,
        cadence: 'weekly',
        contribution_naira: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('savings.plans.store'));
    };

    // Rough client-side projection to show the customer what their pace means.
    const contributionNaira = Number(form.data.contribution_naira) || 0;
    const priceNaira = product.priceKobo / 100;
    const cycles = contributionNaira > 0 ? Math.ceil(priceNaira / contributionNaira) : null;
    const cycleDays = form.data.cadence === 'daily' ? 1 : form.data.cadence === 'weekly' ? 7 : 30;

    return (
        <AccountLayout title="Start a plan">
            <Head title={`Save for ${product.name}`} />

            <Link
                href={route('catalog.product', product.slug)}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Back to product
            </Link>

            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">Save Small Small</h1>
            <p className="mt-1 text-sm text-gray-500">
                Lock today's price and save toward it at your own pace. No loans, no interest, no cash withdrawal.
            </p>

            {/* Product summary w/ locked price */}
            <div className="mt-5 flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <span className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                    {product.image ? (
                        <img src={product.image} alt="" className="h-full w-full object-cover" />
                    ) : (
                        <PackageCheck className="h-7 w-7 text-gray-300" />
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-bold text-gray-900">{product.name}</p>
                    <p className="mt-1 flex items-center gap-1.5 text-xs font-semibold text-brand-700">
                        <Lock className="h-3.5 w-3.5" /> Price locked at {formatNairaFromKobo(product.priceKobo)}
                    </p>
                </div>
            </div>

            <form onSubmit={submit} className="mt-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                {/* Cadence */}
                <p className="text-sm font-semibold text-gray-900">How often will you save?</p>
                <div className="mt-2 grid grid-cols-3 gap-2">
                    {CADENCES.map((cadence) => (
                        <button
                            key={cadence.value}
                            type="button"
                            onClick={() => form.setData('cadence', cadence.value)}
                            className={cn(
                                'rounded-xl border px-3 py-3 text-center transition active:scale-95',
                                form.data.cadence === cadence.value
                                    ? 'border-brand-600 bg-brand-50'
                                    : 'border-gray-200 hover:border-brand-300',
                            )}
                        >
                            <span
                                className={cn(
                                    'block text-sm font-bold',
                                    form.data.cadence === cadence.value ? 'text-brand-700' : 'text-gray-900',
                                )}
                            >
                                {cadence.label}
                            </span>
                            <span className="mt-0.5 block text-[11px] text-gray-400">{cadence.hint}</span>
                        </button>
                    ))}
                </div>
                <InputError message={form.errors.cadence} className="mt-1" />

                {/* Contribution amount */}
                <p className="mt-5 text-sm font-semibold text-gray-900">
                    How much each time? <span className="font-normal text-gray-400">(you can always change pace)</span>
                </p>
                <div className="mt-2 flex items-center rounded-2xl border border-gray-200 px-4 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/15">
                    <span className="text-lg font-bold text-gray-400">₦</span>
                    <input
                        type="number"
                        min="100"
                        step="1"
                        inputMode="numeric"
                        placeholder="e.g. 5,000"
                        value={form.data.contribution_naira}
                        onChange={(e) => form.setData('contribution_naira', e.target.value)}
                        className="w-full border-0 bg-transparent px-2 py-3 text-xl font-extrabold text-gray-900 focus:outline-none focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
                    />
                </div>
                <InputError message={form.errors.contribution_naira} className="mt-1" />

                {/* Projection */}
                {cycles !== null && cycles > 0 && (
                    <p className="mt-3 flex items-center gap-1.5 rounded-xl bg-brand-50/70 px-4 py-3 text-sm text-gray-600">
                        <CalendarClock className="h-4 w-4 shrink-0 text-brand-600" />
                        About <strong className="text-gray-900">{cycles}</strong>{' '}
                        {form.data.cadence === 'daily' ? 'days' : form.data.cadence === 'weekly' ? 'weeks' : 'months'}{' '}
                        to reach {formatNairaFromKobo(product.priceKobo)} — roughly{' '}
                        {new Date(Date.now() + cycles * cycleDays * 86400000).toLocaleDateString('en-NG', {
                            day: 'numeric',
                            month: 'short',
                            year: 'numeric',
                        })}
                        .
                    </p>
                )}

                <Button type="submit" disabled={form.processing} className="mt-5 w-full active:scale-[0.98]">
                    {form.processing ? 'Locking price…' : 'Lock price and start my plan'}
                </Button>
                <p className="mt-3 text-center text-xs text-gray-400">
                    Wallet balance: {formatNairaFromKobo(walletBalanceKobo)} — contributions come from your wallet or
                    Open Savings whenever you choose.
                </p>
            </form>

            {/* Prefer to pay now? */}
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4 shadow-sm">
                <p className="flex items-center gap-2 text-sm text-gray-600">
                    <Zap className="h-4 w-4 text-brand-yellow" /> Have the full amount? Pay at once and get it
                    delivered.
                </p>
                <Link
                    href={route('checkout.pay-at-once', product.slug)}
                    className="text-sm font-bold text-brand-600 hover:underline"
                >
                    Pay At Once →
                </Link>
            </div>
        </AccountLayout>
    );
}
