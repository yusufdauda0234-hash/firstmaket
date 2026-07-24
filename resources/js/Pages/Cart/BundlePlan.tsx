import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Lock, PackageCheck, ShieldAlert } from 'lucide-react';
import { FormEventHandler } from 'react';

interface BundleItem {
    id: number;
    productName: string;
    productImage: string | null;
    vendorName: string;
    priceKobo: number;
    quantity: number;
}

interface Props {
    items: BundleItem[];
    targetPriceKobo: number;
    ineligibleReason: string | null;
    [key: string]: unknown;
}

const CADENCES = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
] as const;

export default function CartBundlePlan() {
    const { items, targetPriceKobo, ineligibleReason } = usePage<Props>().props;

    const form = useForm({
        items: items.map((item) => item.id),
        cadence: 'weekly',
        contribution_naira: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('cart.bundle-plan.store'));
    };

    return (
        <AccountLayout title="Bundle plan">
            <Head title="Bundle these into one plan" />

            <Link
                href={route('cart.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Back to cart
            </Link>

            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">Bundle into one plan</h1>
            <p className="mt-1 text-sm text-gray-500">
                Save toward all {items.length} products at once, at today's combined price. They deliver together, only
                once the full target is funded.
            </p>

            {ineligibleReason && (
                <Card className="mt-5 border-amber-200 bg-amber-50">
                    <p className="flex items-start gap-2 text-sm text-amber-800">
                        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" /> {ineligibleReason}
                    </p>
                </Card>
            )}

            <Card className="mt-5 p-0">
                <ul className="divide-y divide-gray-100">
                    {items.map((item) => (
                        <li key={item.id} className="flex items-center gap-4 px-5 py-3.5">
                            <span className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                                {item.productImage ? (
                                    <img src={item.productImage} alt="" className="h-full w-full object-cover" />
                                ) : (
                                    <PackageCheck className="h-5 w-5 text-gray-300" />
                                )}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-semibold text-gray-900">{item.productName}</p>
                                <p className="text-xs text-gray-400">
                                    {item.vendorName} · Qty {item.quantity}
                                </p>
                            </div>
                            <p className="text-sm font-bold text-gray-900">
                                {formatNairaFromKobo(item.priceKobo * item.quantity)}
                            </p>
                        </li>
                    ))}
                </ul>
                <div className="flex items-center justify-between border-t border-gray-100 px-5 py-4">
                    <span className="flex items-center gap-1.5 text-sm font-semibold text-gray-900">
                        <Lock className="h-3.5 w-3.5" /> Combined target
                    </span>
                    <span className="text-lg font-extrabold text-gray-900">{formatNairaFromKobo(targetPriceKobo)}</span>
                </div>
            </Card>

            {!ineligibleReason && (
                <form onSubmit={submit} className="mt-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p className="text-sm font-semibold text-gray-900">How often will you save?</p>
                    <div className="mt-2 grid grid-cols-3 gap-2">
                        {CADENCES.map((cadence) => (
                            <button
                                key={cadence.value}
                                type="button"
                                onClick={() => form.setData('cadence', cadence.value)}
                                className={cn(
                                    'rounded-xl border px-3 py-3 text-center text-sm font-bold transition active:scale-95',
                                    form.data.cadence === cadence.value
                                        ? 'border-brand-600 bg-brand-50 text-brand-700'
                                        : 'border-gray-200 text-gray-900 hover:border-brand-300',
                                )}
                            >
                                {cadence.label}
                            </button>
                        ))}
                    </div>
                    <InputError message={form.errors.cadence} className="mt-1" />

                    <p className="mt-5 text-sm font-semibold text-gray-900">How much each time?</p>
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
                    <InputError message={(form.errors as Record<string, string>).eligibility} className="mt-1" />
                    <InputError message={(form.errors as Record<string, string>).items} className="mt-1" />

                    <Button type="submit" disabled={form.processing} className="mt-5 w-full active:scale-[0.98]">
                        {form.processing ? 'Locking prices…' : 'Lock prices and start bundle plan'}
                    </Button>
                </form>
            )}
        </AccountLayout>
    );
}
