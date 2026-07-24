import { Button } from '@/Components/ui/Button';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Printer } from 'lucide-react';

interface Props {
    receipt: {
        receiptNumber: string;
        amountKobo: number;
        currency: string;
        channel: string | null;
        issuedAt: string;
        customerName: string;
    };
    [key: string]: unknown;
}

const channelLabel: Record<string, string> = {
    card: 'Card',
    bank: 'Bank transfer',
    bank_transfer: 'Bank transfer',
    ussd: 'USSD',
};

export default function WalletReceipt({ receipt }: Props) {
    return (
        <PublicLayout>
            <Head title={`Receipt ${receipt.receiptNumber}`} />

            <div className="mx-auto max-w-lg px-4 py-8">
                <div className="mb-4 flex items-center justify-between print:hidden">
                    <Link
                        href={route('wallet.transactions')}
                        className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
                    >
                        <ArrowLeft className="h-4 w-4" /> Back
                    </Link>
                    <Button variant="secondary" onClick={() => window.print()} className="active:scale-95">
                        <Printer className="mr-2 h-4 w-4" /> Print / Save PDF
                    </Button>
                </div>

                <div className="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-200 dark:bg-white">
                    {/* Header */}
                    <div className="bg-gradient-to-br from-brand-700 to-brand-900 px-8 py-7 text-center text-white">
                        <img src="/images/brand/logo-light-transparent.png" alt="FirstMaket" className="mx-auto h-12 w-auto" />
                        <p className="mt-4 inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold">
                            <CheckCircle2 className="h-3.5 w-3.5" /> Payment received
                        </p>
                        <p className="mt-4 text-3xl font-extrabold tracking-tight">
                            {formatNairaFromKobo(receipt.amountKobo)}
                        </p>
                    </div>

                    {/* Details */}
                    <dl className="divide-y divide-gray-100 px-8 py-2 text-sm">
                        <Row label="Receipt number" value={receipt.receiptNumber} mono />
                        <Row label="Paid by" value={receipt.customerName} />
                        <Row label="Channel" value={receipt.channel ? channelLabel[receipt.channel] ?? receipt.channel : 'Paystack'} />
                        <Row label="Date" value={receipt.issuedAt} />
                        <Row label="Purpose" value="Wallet top-up" />
                    </dl>

                    <p className="border-t border-gray-100 px-8 py-5 text-center text-xs leading-relaxed text-gray-400">
                        This is an official FirstMarketreceipt for a wallet deposit. Deposits are non-withdrawable
                        and can be used to pay at once or save toward a product. Secured by Paystack.
                    </p>
                </div>
            </div>
        </PublicLayout>
    );
}

function Row({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="flex items-center justify-between py-3">
            <dt className="text-gray-500">{label}</dt>
            <dd className={`font-semibold text-gray-900 ${mono ? 'font-mono' : ''}`}>{value}</dd>
        </div>
    );
}
