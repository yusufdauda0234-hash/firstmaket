import { Badge } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui/Card';
import { Pagination, PaginationLink } from '@/Components/ui/Pagination';
import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, ReceiptText } from 'lucide-react';

interface ReceiptRow {
    uuid: string;
    number: string;
    totalKobo: number;
    itemCount: number;
    method: string;
    paidInFull: boolean;
    issuedAt: string;
}

interface Props {
    receipts: { data: ReceiptRow[]; links: PaginationLink[]; total: number };
    [key: string]: unknown;
}

export default function Receipts() {
    const { receipts } = usePage<Props>().props;

    return (
        <AccountLayout title="Receipts">
            <Head title="Receipts" />

            {receipts.data.length === 0 ? (
                <Card className="p-10 text-center">
                    <ReceiptText className="mx-auto h-10 w-10 text-gray-300" />
                    <p className="mt-3 text-sm text-gray-600">
                        Your receipts will appear here once you place your first order.
                    </p>
                </Card>
            ) : (
                <>
                    <ul className="space-y-3">
                        {receipts.data.map((receipt) => (
                            <li key={receipt.uuid}>
                                <Link
                                    href={route('receipts.show', receipt.uuid)}
                                    className="flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-sm"
                                >
                                    <div className="min-w-0">
                                        <p className="font-mono text-sm font-medium tabular-nums text-gray-900">
                                            {receipt.number}
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-500">
                                            {receipt.issuedAt} · {receipt.itemCount}{' '}
                                            {receipt.itemCount === 1 ? 'item' : 'items'} · {receipt.method}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-3">
                                        <span className="text-sm font-semibold tabular-nums text-gray-900">
                                            {formatNairaFromKobo(receipt.totalKobo)}
                                        </span>
                                        <Badge tone={receipt.paidInFull ? 'success' : 'warning'}>
                                            {receipt.paidInFull ? 'Paid' : 'Balance due'}
                                        </Badge>
                                        <ChevronRight className="h-4 w-4 text-gray-300" />
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>

                    <Pagination links={receipts.links} />
                </>
            )}
        </AccountLayout>
    );
}
