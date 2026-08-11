import VendorLayout from '@/Layouts/VendorLayout';
import PageHeader from '@/Components/ui/PageHeader';
import { Head, router, usePage } from '@inertiajs/react';
import { PackageCheck, RotateCcw } from 'lucide-react';

interface ReturnRow {
    uuid: string;
    status: string;
    statusLabel: string;
    reasonLabel: string;
    reasonNote: string | null;
    productName: string | null;
    orderUuid: string | null;
    openedAt: string;
    requiredUnopened: boolean;
    canMarkReceived: boolean;
    canContest: boolean;
}

interface Props {
    returns: ReturnRow[];
    [key: string]: unknown;
}

/**
 * The vendor's returns queue.
 *
 * Two actions only: confirm the item arrived, or say its condition is not what
 * was claimed. Neither approves nor refuses the return — the vendor is the
 * party who loses the sale, so the decision sits with our team.
 */
export default function VendorReturns() {
    const { returns } = usePage<Props>().props;

    return (
        <VendorLayout>
            <Head title="Returns" />
            <PageHeader
                title="Returns"
                description="Items coming back. Confirm what arrives, and tell us if the condition does not match what was reported."
            />

            {returns.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center">
                    <RotateCcw className="mx-auto h-10 w-10 text-gray-300" />
                    <p className="mt-3 text-sm font-semibold text-gray-700">No returns</p>
                    <p className="mt-1 text-sm text-gray-500">Nothing has been sent back to you.</p>
                </div>
            ) : (
                <ul className="space-y-3">
                    {returns.map((row) => (
                        <li
                            key={row.uuid}
                            className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-bold text-gray-900">
                                        {row.productName ?? 'Item'}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-500">
                                        {row.reasonLabel} · opened {row.openedAt}
                                        {row.requiredUnopened && ' · must be unopened'}
                                    </p>
                                </div>
                                <span className="shrink-0 rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold text-slate-600">
                                    {row.statusLabel}
                                </span>
                            </div>

                            {row.reasonNote && (
                                <p className="mt-3 rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm leading-relaxed text-gray-600">
                                    “{row.reasonNote}”
                                </p>
                            )}

                            {(row.canMarkReceived || row.canContest) && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {row.canMarkReceived && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(route('vendor.returns.received', row.uuid), {}, {
                                                    preserveScroll: true,
                                                })
                                            }
                                            className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95"
                                        >
                                            <PackageCheck className="h-3.5 w-3.5" /> Mark received
                                        </button>
                                    )}
                                    {row.canContest && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                const reason = prompt(
                                                    'What is different about the condition? Our team will review it.',
                                                );

                                                if (reason && reason.trim().length >= 10) {
                                                    router.post(
                                                        route('vendor.returns.contest', row.uuid),
                                                        { reason },
                                                        { preserveScroll: true },
                                                    );
                                                }
                                            }}
                                            className="rounded-full border border-gray-200 px-4 py-2 text-xs font-bold text-gray-600 transition hover:border-amber-300 hover:text-amber-700"
                                        >
                                            Condition does not match
                                        </button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </VendorLayout>
    );
}
