import { Button } from '@/Components/ui/Button';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Clock, HelpCircle, XCircle } from 'lucide-react';
import { useEffect } from 'react';

interface Props {
    state: 'success' | 'pending' | 'failed' | 'unknown';
    amountKobo: number | null;
    reference: string;
    [key: string]: unknown;
}

const config = {
    success: {
        icon: CheckCircle2,
        accent: 'bg-emerald-100 text-emerald-600',
        title: 'Payment confirmed',
        body: 'Payment received. Your plan has been updated. You can start shopping or saving right away.',
    },
    pending: {
        icon: Clock,
        accent: 'bg-amber-100 text-amber-600',
        title: 'Confirming your payment…',
        body: 'This usually takes a few seconds. Your balance updates automatically once Paystack confirms it — no need to pay again.',
    },
    failed: {
        icon: XCircle,
        accent: 'bg-red-100 text-red-600',
        title: 'Payment not completed',
        body: 'We could not confirm this payment. If money left your account, it will be reversed by your bank. You can try again.',
    },
    unknown: {
        icon: HelpCircle,
        accent: 'bg-gray-100 text-gray-500',
        title: 'We couldn’t find this payment',
        body: 'If you just paid, give it a moment and check your savings. Otherwise, check your plans.',
    },
} as const;

export default function PaymentCallback({ state, amountKobo, reference }: Props) {
    const c = config[state];

    // While pending, the webhook is likely still in flight — refresh the
    // status a few times so "pending" flips to "success" without a manual reload.
    useEffect(() => {
        if (state !== 'pending') return;
        const id = window.setInterval(() => {
            router.reload({ only: ['state', 'amountKobo'] });
        }, 4000);
        const stop = window.setTimeout(() => window.clearInterval(id), 40000);
        return () => {
            window.clearInterval(id);
            window.clearTimeout(stop);
        };
    }, [state]);

    return (
        <PublicLayout>
            <Head title="Payment status" />

            <div className="mx-auto max-w-md px-4 py-12 text-center">
                <span className={`mx-auto flex h-16 w-16 items-center justify-center rounded-2xl ${c.accent}`}>
                    <c.icon className="h-8 w-8" />
                </span>
                <h1 className="mt-5 text-2xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">
                    {c.title}
                </h1>
                {amountKobo != null && (
                    <p className="mt-1 text-lg font-bold text-brand-700 dark:text-brand-300">
                        {formatNairaFromKobo(amountKobo)}
                    </p>
                )}
                <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-gray-500 dark:text-gray-400">{c.body}</p>
                {state === 'pending' && (
                    <p className="mt-3 flex items-center justify-center gap-2 text-xs text-gray-400">
                        <span className="h-2 w-2 animate-ping rounded-full bg-amber-400" /> Checking with Paystack…
                    </p>
                )}

                <div className="mt-6 flex flex-col gap-2">
                    <Link href={route('savings.index')}>
                        <Button className="w-full">Go to my savings</Button>
                    </Link>
                    {(state === 'failed' || state === 'unknown') && (
                        <Link href={route('savings.index')}>
                            <Button variant="secondary" className="w-full">
                                Try again
                            </Button>
                        </Link>
                    )}
                </div>

                {reference && <p className="mt-4 text-[11px] text-gray-400">Ref: {reference}</p>}
            </div>
        </PublicLayout>
    );
}
