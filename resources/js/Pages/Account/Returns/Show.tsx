import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, PackageCheck, Truck } from 'lucide-react';

interface TimelineEntry {
    status: string;
    label: string;
    note: string | null;
    at: string;
}

interface Props {
    return: {
        uuid: string;
        status: string;
        statusLabel: string;
        reasonLabel: string;
        reasonNote: string | null;
        reviewNote: string | null;
        refundableKobo: number;
        openedAt: string;
        productName: string | null;
        orderUuid: string | null;
        returnDeliveryPaidBy: string;
        requiredUnopened: boolean;
        refundDaysMin: number;
        refundDaysMax: number;
        canCancel: boolean;
        canMarkShipped: boolean;
        timeline: TimelineEntry[];
    };
    [key: string]: unknown;
}

export default function ReturnShow() {
    const { return: request } = usePage<Props>().props;
    const platformPays = request.returnDeliveryPaidBy === 'platform';

    return (
        <AccountLayout title="Return">
            <Head title={`Return — ${request.productName ?? 'item'}`} />

            <Link
                href={route('returns.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> All returns
            </Link>

            <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div className="min-w-0">
                        <h1 className="text-lg font-extrabold tracking-tight text-gray-900">
                            {request.productName ?? 'Item'}
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            {request.reasonLabel} · opened {request.openedAt}
                        </p>
                    </div>
                    <span className="shrink-0 rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">
                        {request.statusLabel}
                    </span>
                </div>

                {request.reasonNote && (
                    <p className="mt-3 rounded-xl bg-slate-50 px-4 py-3 text-sm leading-relaxed text-gray-600">
                        “{request.reasonNote}”
                    </p>
                )}

                {/* Who pays, stated plainly and early — this is the single
                    thing customers most often find out too late. */}
                <p
                    className={`mt-4 rounded-xl px-4 py-3 text-sm leading-relaxed ${
                        platformPays
                            ? 'bg-emerald-50 text-emerald-900'
                            : 'bg-amber-50 text-amber-900'
                    }`}
                >
                    {platformPays ? (
                        <>
                            We cover the return delivery on this one, and you will be refunded{' '}
                            {formatNairaFromKobo(request.refundableKobo)} in full.
                        </>
                    ) : (
                        <>
                            The return delivery is yours to pay on a change of mind, and the item must
                            come back unopened. You will be refunded{' '}
                            {formatNairaFromKobo(request.refundableKobo)}.
                        </>
                    )}
                </p>

                {request.reviewNote && (
                    <p className="mt-3 rounded-xl border border-gray-200 px-4 py-3 text-sm leading-relaxed text-gray-600">
                        <span className="font-bold text-gray-900">From our team: </span>
                        {request.reviewNote}
                    </p>
                )}

                {request.status === 'refunded' && (
                    <p className="mt-3 text-sm text-gray-500">
                        Refunds reach your card within {request.refundDaysMin}–{request.refundDaysMax}{' '}
                        working days.
                    </p>
                )}

                <div className="mt-5 flex flex-wrap gap-2">
                    {request.canMarkShipped && (
                        <button
                            type="button"
                            onClick={() => router.post(route('returns.shipped', request.uuid))}
                            className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                        >
                            <Truck className="h-4 w-4" /> I have sent it back
                        </button>
                    )}
                    {request.canCancel && (
                        <button
                            type="button"
                            onClick={() => {
                                if (confirm('Cancel this return request?')) {
                                    router.post(route('returns.cancel', request.uuid));
                                }
                            }}
                            className="rounded-full border border-gray-200 px-5 py-2.5 text-sm font-bold text-gray-600 transition hover:border-red-200 hover:text-red-600"
                        >
                            Cancel return
                        </button>
                    )}
                    {request.orderUuid && (
                        <Link
                            href={route('orders.show', request.orderUuid)}
                            className="rounded-full px-5 py-2.5 text-sm font-bold text-gray-500 transition hover:bg-gray-100"
                        >
                            View the order
                        </Link>
                    )}
                </div>
            </div>

            {/* ── Timeline ── */}
            <div className="mt-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 className="flex items-center gap-2 text-sm font-bold text-gray-900">
                    <PackageCheck className="h-4 w-4 text-brand-600" /> Progress
                </h2>

                <ol className="mt-4 space-y-4">
                    {request.timeline.map((entry, index) => (
                        <li key={index} className="flex gap-3">
                            <span className="relative flex flex-col items-center">
                                <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-brand-600" />
                                {index < request.timeline.length - 1 && (
                                    <span className="mt-1 w-px flex-1 bg-gray-200" />
                                )}
                            </span>
                            <span className="min-w-0 flex-1 pb-1">
                                <span className="block text-sm font-semibold text-gray-900">
                                    {entry.label}
                                </span>
                                <span className="block text-xs text-gray-400">{entry.at}</span>
                                {entry.note && (
                                    <span className="mt-1 block text-xs leading-relaxed text-gray-500">
                                        {entry.note}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ol>
            </div>
        </AccountLayout>
    );
}
