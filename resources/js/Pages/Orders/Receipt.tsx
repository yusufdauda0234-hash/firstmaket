import { Button } from '@/Components/ui/Button';
import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

interface ReceiptLine {
    name: string;
    quantity: number;
    unitPriceKobo: number;
    lineTotalKobo: number;
}

interface Props {
    receipt: {
        number: string;
        issuedAt: string;
        currency: string;
        items: ReceiptLine[];
        subtotalKobo: number;
        shippingKobo: number;
        discountKobo: number;
        totalKobo: number;
        paidKobo: number;
        collectOnDeliveryKobo: number;
        method: string;
        reference: string | null;
        billedTo: {
            name?: string;
            email?: string;
            phone?: string;
            recipient?: string;
            address?: string;
            lga?: string;
            state?: string;
            landmark?: string;
        };
    };
    [key: string]: unknown;
}

/**
 * The receipt document.
 *
 * Built to survive being printed: `print:hidden` strips the site chrome and
 * the buttons, so "Save as PDF" from any browser produces a clean sheet with
 * the number, the lines, the totals and the delivery address — which is what
 * a customer forwards to an employer or files for a warranty claim. No PDF
 * library involved, and therefore no second version of the document that can
 * drift from the record.
 */
export default function Receipt() {
    const { receipt } = usePage<Props>().props;
    const outstanding = receipt.collectOnDeliveryKobo > 0;

    return (
        <AccountLayout title="Receipt">
            <Head title={`Receipt ${receipt.number}`} />

            <div className="mb-4 flex items-center justify-between print:hidden">
                <Link
                    href={route('receipts.index')}
                    className="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 hover:text-brand-700"
                >
                    <ArrowLeft className="h-4 w-4" /> All receipts
                </Link>
                <Button type="button" onClick={() => window.print()}>
                    <Printer className="mr-1.5 h-4 w-4" /> Print or save as PDF
                </Button>
            </div>

            {/* data-print-sheet: everything outside this element is hidden
                when printed — see the @media print block in app.css. */}
            <article
                data-print-sheet
                className="mx-auto max-w-3xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm print:max-w-none print:rounded-none print:border-0 print:p-0 print:shadow-none sm:p-10"
            >
                <header className="flex flex-wrap items-start justify-between gap-6 border-b border-gray-200 pb-6">
                    <div>
                        <img src="/images/brand/logo-mark-dark.png" alt="FirstMaket" className="h-10 w-auto" />
                        <p className="mt-3 text-sm text-gray-500">
                            FirstMaket
                            <br />
                            Nigeria
                        </p>
                    </div>
                    <div className="text-right">
                        <h1 className="text-xl font-bold uppercase tracking-wide text-gray-900">Receipt</h1>
                        <p className="mt-1 font-mono text-sm tabular-nums text-gray-900">{receipt.number}</p>
                        <p className="mt-0.5 text-sm text-gray-500">{receipt.issuedAt}</p>
                        {/* Said plainly on the document itself. A receipt that
                            looks settled while the courier is still owed cash
                            is the one thing this page must never do. */}
                        <p
                            className={`mt-2 inline-block rounded-full px-3 py-1 text-xs font-semibold ${
                                outstanding ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800'
                            }`}
                        >
                            {outstanding ? 'Balance due on delivery' : 'Paid in full'}
                        </p>
                    </div>
                </header>

                <section className="grid gap-6 border-b border-gray-200 py-6 sm:grid-cols-2">
                    <div>
                        <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500">Billed to</h2>
                        <p className="mt-2 text-sm font-medium text-gray-900">{receipt.billedTo.name}</p>
                        {receipt.billedTo.email && <p className="text-sm text-gray-600">{receipt.billedTo.email}</p>}
                        {receipt.billedTo.phone && <p className="text-sm text-gray-600">{receipt.billedTo.phone}</p>}
                    </div>
                    <div>
                        <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500">Delivered to</h2>
                        {receipt.billedTo.recipient && (
                            <p className="mt-2 text-sm font-medium text-gray-900">{receipt.billedTo.recipient}</p>
                        )}
                        <p className="text-sm text-gray-600">{receipt.billedTo.address}</p>
                        <p className="text-sm text-gray-600">
                            {[receipt.billedTo.lga, receipt.billedTo.state].filter(Boolean).join(', ')}
                        </p>
                        {receipt.billedTo.landmark && (
                            <p className="text-sm text-gray-500">Landmark: {receipt.billedTo.landmark}</p>
                        )}
                    </div>
                </section>

                <section className="py-6">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wider text-gray-500">
                                    <th className="pb-2 font-semibold">Item</th>
                                    <th className="pb-2 text-right font-semibold">Qty</th>
                                    <th className="pb-2 text-right font-semibold">Unit price</th>
                                    <th className="pb-2 text-right font-semibold">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {receipt.items.map((line, index) => (
                                    <tr key={`${line.name}-${index}`}>
                                        <td className="py-3 pr-4 text-gray-900">{line.name}</td>
                                        <td className="py-3 text-right tabular-nums text-gray-600">{line.quantity}</td>
                                        <td className="py-3 text-right tabular-nums text-gray-600">
                                            {formatNairaFromKobo(line.unitPriceKobo)}
                                        </td>
                                        <td className="py-3 text-right font-medium tabular-nums text-gray-900">
                                            {formatNairaFromKobo(line.lineTotalKobo)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <dl className="ml-auto mt-6 w-full max-w-xs space-y-2 text-sm">
                        <Row label="Subtotal" value={formatNairaFromKobo(receipt.subtotalKobo)} />
                        <Row label="Delivery" value={formatNairaFromKobo(receipt.shippingKobo)} />
                        {receipt.discountKobo > 0 && (
                            <Row label="Discount" value={`− ${formatNairaFromKobo(receipt.discountKobo)}`} />
                        )}
                        <div className="flex justify-between border-t border-gray-200 pt-2 text-base font-bold text-gray-900">
                            <dt>Total</dt>
                            <dd className="tabular-nums">{formatNairaFromKobo(receipt.totalKobo)}</dd>
                        </div>
                        {outstanding && (
                            <>
                                <Row label="Paid now" value={formatNairaFromKobo(receipt.paidKobo)} />
                                <div className="flex justify-between font-semibold text-amber-700">
                                    <dt>Due on delivery</dt>
                                    <dd className="tabular-nums">{formatNairaFromKobo(receipt.collectOnDeliveryKobo)}</dd>
                                </div>
                            </>
                        )}
                    </dl>
                </section>

                <footer className="border-t border-gray-200 pt-6 text-xs text-gray-500">
                    <p>
                        <span className="font-medium text-gray-700">Payment method:</span> {receipt.method}
                        {receipt.reference && (
                            <>
                                {' · '}
                                <span className="font-medium text-gray-700">Reference:</span>{' '}
                                <span className="font-mono">{receipt.reference}</span>
                            </>
                        )}
                    </p>
                    <p className="mt-2">
                        Quote receipt number <span className="font-mono text-gray-700">{receipt.number}</span> when you contact
                        support about this order. This receipt was issued electronically and is valid without a signature.
                    </p>
                </footer>
            </article>
        </AccountLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between text-gray-600">
            <dt>{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}
