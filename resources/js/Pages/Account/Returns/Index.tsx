import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, usePage } from '@inertiajs/react';
import { Package, RotateCcw } from 'lucide-react';

interface ReturnRow {
    uuid: string;
    status: string;
    statusLabel: string;
    reason: string;
    reasonLabel: string;
    refundableKobo: number;
    openedAt: string;
    productName: string | null;
    productSlug: string | null;
    orderUuid: string | null;
}

interface Props {
    returns: ReturnRow[];
    [key: string]: unknown;
}

/** Tone per state, so a glance says whether anything is owed the customer. */
const TONE: Record<string, string> = {
    requested: 'bg-amber-100 text-amber-800',
    approved: 'bg-brand-100 text-brand-800',
    in_transit: 'bg-brand-100 text-brand-800',
    received: 'bg-brand-100 text-brand-800',
    disputed: 'bg-amber-100 text-amber-800',
    refunded: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-600',
};

export default function ReturnsIndex() {
    const { returns } = usePage<Props>().props;

    return (
        <AccountLayout title="Returns">
            <Head title="My returns" />

            <div className="mb-4">
                <h1 className="text-xl font-extrabold tracking-tight text-gray-900">Returns</h1>
                <p className="mt-1 text-sm text-gray-500">
                    Problems you have reported, and where each one has got to.
                </p>
            </div>

            {returns.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center">
                    <RotateCcw className="mx-auto h-10 w-10 text-gray-300" />
                    <p className="mt-3 text-sm font-semibold text-gray-700">No returns</p>
                    <p className="mt-1 text-sm text-gray-500">
                        If something arrives damaged or is not what you expected, open the order and
                        report it there.
                    </p>
                    <Link
                        href={route('orders.index')}
                        className="mt-5 inline-block rounded-full bg-brand-600 px-6 py-3 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                    >
                        View my orders
                    </Link>
                </div>
            ) : (
                <ul className="space-y-3">
                    {returns.map((row) => (
                        <li key={row.uuid}>
                            <Link
                                href={route('returns.show', row.uuid)}
                                className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-brand-200 hover:shadow-md"
                            >
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gray-50">
                                    <Package className="h-5 w-5 text-gray-300" />
                                </span>

                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-bold text-gray-900">
                                        {row.productName ?? 'Item'}
                                    </span>
                                    <span className="mt-0.5 block text-xs text-gray-500">
                                        {row.reasonLabel} · opened {row.openedAt}
                                    </span>
                                </span>

                                <span className="shrink-0 text-right">
                                    <span className="block text-sm font-bold tabular-nums text-gray-900">
                                        {formatNairaFromKobo(row.refundableKobo)}
                                    </span>
                                    <span
                                        className={`mt-1 inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold ${
                                            TONE[row.status] ?? 'bg-gray-100 text-gray-600'
                                        }`}
                                    >
                                        {row.statusLabel}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </AccountLayout>
    );
}
