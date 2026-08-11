import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination } from '@/Components/ui/Pagination';
import { Select } from '@/Components/ui/Select';
import { Paginated } from '@/Types';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, router, usePage } from '@inertiajs/react';
import { PiggyBank, RotateCcw } from 'lucide-react';

interface ReturnRow {
    uuid: string;
    status: string;
    statusLabel: string;
    reasonLabel: string;
    reasonNote: string | null;
    refundableKobo: number;
    returnDeliveryPaidBy: string;
    customerName: string | null;
    vendorName: string | null;
    productName: string | null;
    orderUuid: string | null;
    openedAt: string;
    refundsToPlan: boolean;
    canDecide: boolean;
    canRefund: boolean;
}

interface Props {
    returns: Paginated<ReturnRow>;
    filters: { status: string };
    statuses: { value: string; label: string }[];
    /** False for a support agent: they run the desk, finance moves the money. */
    canIssueRefunds: boolean;
    [key: string]: unknown;
}

export default function AdminReturns() {
    const { returns, filters, statuses, canIssueRefunds } = usePage<Props>().props;

    return (
        <AdminLayout>
            <Head title="Returns" />
            <PageHeader
                eyebrow="Phase 2E"
                title="Returns"
                description="Oldest first — a returns queue is a promise with a clock on it."
            />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Select
                    aria-label="Filter by status"
                    value={filters.status}
                    onChange={(event) =>
                        router.get(route('admin.returns.index'), { status: event.target.value }, {
                            preserveState: true,
                            replace: true,
                        })
                    }
                    className="max-w-xs"
                >
                    <option value="">All statuses</option>
                    {statuses.map((status) => (
                        <option key={status.value} value={status.value}>
                            {status.label}
                        </option>
                    ))}
                </Select>

                {!canIssueRefunds && (
                    <p className="text-xs text-gray-500">
                        You can review returns but not issue refunds.
                    </p>
                )}
            </div>

            {returns.data.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center">
                    <RotateCcw className="mx-auto h-10 w-10 text-gray-300" />
                    <p className="mt-3 text-sm font-semibold text-gray-700">Nothing in the queue</p>
                </div>
            ) : (
                <div className="space-y-3">
                    {returns.data.map((row) => (
                        <article
                            key={row.uuid}
                            className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-bold text-gray-900">
                                        {row.productName ?? 'Item'}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-500">
                                        {row.customerName} · sold by {row.vendorName} · opened{' '}
                                        {row.openedAt}
                                    </p>
                                    <p className="mt-1 text-xs font-semibold text-gray-600">
                                        {row.reasonLabel}
                                        <span className="ml-2 font-normal text-gray-400">
                                            {row.returnDeliveryPaidBy === 'platform'
                                                ? 'we pay return delivery'
                                                : 'customer pays return delivery'}
                                        </span>
                                    </p>
                                </div>

                                <div className="shrink-0 text-right">
                                    <p className="text-sm font-extrabold tabular-nums text-gray-900">
                                        {formatNairaFromKobo(row.refundableKobo)}
                                    </p>
                                    <span className="mt-1 inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold text-slate-600">
                                        {row.statusLabel}
                                    </span>
                                    {/* A plan order can only ever return value
                                        as plan credit — worth saying before the
                                        button is pressed, not after. */}
                                    {row.refundsToPlan && (
                                        <span className="mt-1 flex items-center justify-end gap-1 text-[11px] font-semibold text-brand-700">
                                            <PiggyBank className="h-3 w-3" /> refunds as plan credit
                                        </span>
                                    )}
                                </div>
                            </div>

                            {row.reasonNote && (
                                <p className="mt-3 rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm leading-relaxed text-gray-600">
                                    “{row.reasonNote}”
                                </p>
                            )}

                            <div className="mt-3 flex flex-wrap gap-2">
                                {row.canDecide && (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(route('admin.returns.approve', row.uuid), {}, {
                                                    preserveScroll: true,
                                                })
                                            }
                                            className="rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95"
                                        >
                                            Approve
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                // Always with a reason: an
                                                // unexplained refusal is what
                                                // turns a return into a
                                                // chargeback.
                                                const reason = prompt(
                                                    'Why is this being refused? The customer will see this.',
                                                );

                                                if (reason && reason.trim().length >= 10) {
                                                    router.post(
                                                        route('admin.returns.reject', row.uuid),
                                                        { reason },
                                                        { preserveScroll: true },
                                                    );
                                                }
                                            }}
                                            className="rounded-full border border-gray-200 px-4 py-2 text-xs font-bold text-gray-600 transition hover:border-red-200 hover:text-red-600"
                                        >
                                            Reject
                                        </button>
                                    </>
                                )}

                                {row.canRefund && canIssueRefunds && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    `Refund ${formatNairaFromKobo(row.refundableKobo)}? ` +
                                                        (row.refundsToPlan
                                                            ? 'This returns value to the customer’s plan as credit.'
                                                            : 'This sends money back to the card that paid.') +
                                                        ' The vendor earning is reversed at the same time.',
                                                )
                                            ) {
                                                router.post(route('admin.returns.refund', row.uuid), {}, {
                                                    preserveScroll: true,
                                                });
                                            }
                                        }}
                                        className="rounded-full bg-emerald-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-emerald-700 active:scale-95"
                                    >
                                        Refund {formatNairaFromKobo(row.refundableKobo)}
                                    </button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            )}

            <div className="mt-6">
                <Pagination links={returns.links} />
            </div>
        </AdminLayout>
    );
}
