import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    ChevronDown,
    MapPin,
    Package,
    Phone,
    Store,
} from 'lucide-react';
import { formatNairaFromKobo } from '@/Utils/money';
import { useState } from 'react';

export interface Stop {
    uuid: string;
    contents: string;
    unitCount: number;
    pickupFrom: string;
    pickupAddress: string | null;
    deliverTo: string;
    address: string;
    landmark: string | null;
    recipientName: string | null;
    recipientPhone: string | null;
    status: string;
    statusLabel: string;
    nextStep: string | null;
    nextStepLabel: string | null;
    /** True once the only thing left is to hand it over against the code. */
    awaitingHandover: boolean;
    attemptCount: number;
    lastAttempt: string | null;
    waitingDays: number;
    collectKobo: number;
    goodsPaidAt: string | null;
    goodsCollectionMethod: string;
}

export interface FailureReason {
    value: string;
    label: string;
}

interface Props {
    stop: Stop;
    failureReasons: FailureReason[];
    selected: boolean;
    onSelect: (uuid: string, selected: boolean) => void;
}

/**
 * One stop on a courier's round.
 *
 * A card, not a table row, and deliberately so: this is read on a phone held
 * in one hand at a gate. The two things a courier reaches for — the phone
 * number and the address — are tap targets rather than text, and the action
 * is a full-width button they cannot miss with a thumb.
 */
export default function StopCard({ stop, failureReasons, selected, onSelect }: Props) {
    const [handingOver, setHandingOver] = useState(false);
    const [reporting, setReporting] = useState(false);
    const [collectionMethod, setCollectionMethod] = useState<'cash' | 'customer_online' | 'courier_online'>('cash');

    const advance = useForm({});
    const deliver = useForm({ delivery_code: '' });
    const payGoods = useForm({});
    const fail = useForm({ outcome: '', note: '' });

    const mapsHref = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
        `${stop.address}, ${stop.deliverTo}`,
    )}`;

    return (
        <div
            className={`rounded-2xl border bg-white p-4 shadow-sm transition ${
                selected ? 'border-brand-400 ring-2 ring-brand-100' : 'border-gray-100'
            }`}
        >
            <div className="flex items-start gap-3">
                {/* Only offered when there is a step to take in bulk. A parcel
                    waiting on its code cannot be advanced from a batch. */}
                {stop.nextStep !== null && (
                    <input
                        type="checkbox"
                        checked={selected}
                        onChange={(event) => onSelect(stop.uuid, event.target.checked)}
                        className="mt-1 h-5 w-5 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                        aria-label={`Select ${stop.contents}`}
                    />
                )}

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2 py-1 text-xs font-bold text-gray-700">
                            <Package className="h-3.5 w-3.5" />
                            {stop.unitCount} item{stop.unitCount === 1 ? '' : 's'}
                        </span>
                        <span className="text-xs font-semibold text-gray-500">{stop.statusLabel}</span>
                        {stop.attemptCount > 0 && (
                            <span className="inline-flex items-center gap-1 rounded-lg bg-amber-50 px-2 py-1 text-xs font-bold text-amber-700">
                                <AlertTriangle className="h-3.5 w-3.5" />
                                Try {stop.attemptCount + 1}
                            </span>
                        )}
                        {stop.waitingDays >= 3 && (
                            <span className="rounded-lg bg-red-50 px-2 py-1 text-xs font-bold text-red-700">
                                {stop.waitingDays} days old
                            </span>
                        )}
                    </div>

                    <p className="mt-2 truncate text-base font-bold text-gray-900">{stop.contents}</p>

                    {stop.lastAttempt && (
                        <p className="mt-0.5 text-xs text-amber-700">Last try: {stop.lastAttempt}</p>
                    )}
                </div>
            </div>

            <dl className="mt-3 space-y-2 border-t border-gray-100 pt-3 text-sm">
                <div className="flex gap-2">
                    <Store className="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
                    <div className="min-w-0">
                        <dt className="sr-only">Pick up from</dt>
                        <dd className="font-semibold text-gray-800">{stop.pickupFrom}</dd>
                        {stop.pickupAddress && (
                            <dd className="text-xs text-gray-500">{stop.pickupAddress}</dd>
                        )}
                    </div>
                </div>

                <div className="flex gap-2">
                    <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
                    <div className="min-w-0">
                        <dt className="sr-only">Deliver to</dt>
                        <dd className="font-semibold text-gray-800">{stop.recipientName}</dd>
                        {/* Opens the customer's map app. A courier who has to
                            retype an address into maps will not do it. */}
                        <a
                            href={mapsHref}
                            target="_blank"
                            rel="noreferrer"
                            className="block text-brand-600 underline-offset-2 hover:underline"
                        >
                            {stop.address}
                        </a>
                        <dd className="text-xs text-gray-500">
                            {stop.deliverTo}
                            {stop.landmark && ` · near ${stop.landmark}`}
                        </dd>
                    </div>
                </div>

                {stop.recipientPhone && (
                    <a
                        href={`tel:${stop.recipientPhone}`}
                        className="flex items-center gap-2 font-semibold text-brand-600"
                    >
                        <Phone className="h-4 w-4 shrink-0" />
                        {stop.recipientPhone}
                    </a>
                )}
            </dl>

            {/* ── Actions ── */}
            <div className="mt-4 space-y-2">
                {stop.nextStep !== null && (
                    <button
                        type="button"
                        disabled={advance.processing}
                        onClick={() =>
                            advance.post(route('admin.deliveries.advance', stop.uuid), {
                                preserveScroll: true,
                            })
                        }
                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-gray-800 disabled:opacity-50"
                    >
                        {stop.nextStepLabel} <ArrowRight className="h-4 w-4" />
                    </button>
                )}

                {stop.awaitingHandover && !handingOver && (
                    <button
                        type="button"
                        onClick={() => setHandingOver(true)}
                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white transition hover:bg-emerald-700"
                    >
                        <CheckCircle2 className="h-4 w-4" /> Hand over
                    </button>
                )}

                {handingOver && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                        <p className="text-xs font-semibold text-emerald-900">
                            Ask the customer to read the 4-digit code from their order page.
                        </p>
                        {stop.collectKobo > 0 && (
                            <div className="mt-3 rounded-lg bg-white p-2.5">
                                <p className="text-xs font-bold text-gray-800">
                                    Item payment due: {formatNairaFromKobo(stop.collectKobo)}
                                </p>
                                {stop.goodsPaidAt ? (
                                    <p className="mt-1 text-xs font-semibold text-emerald-700">
                                        Payment confirmed {stop.goodsPaidAt}
                                    </p>
                                ) : (
                                    <div className="mt-2 grid gap-1.5">
                                        {[
                                            ['cash', 'Customer pays cash'],
                                            ['customer_online', 'Customer pays later in the app'],
                                            ['courier_online', 'Courier pays online now'],
                                        ].map(([value, label]) => (
                                            <label key={value} className="flex items-center gap-2 rounded-lg border border-gray-100 px-2.5 py-2 text-xs font-semibold text-gray-700">
                                                <input
                                                    type="radio"
                                                    name={`collection-${stop.uuid}`}
                                                    value={value}
                                                    checked={collectionMethod === value}
                                                    onChange={() => setCollectionMethod(value as typeof collectionMethod)}
                                                    className="text-brand-600 focus:ring-brand-500/20"
                                                />
                                                {label}
                                            </label>
                                        ))}
                                        {collectionMethod === 'courier_online' && (
                                            <button
                                                type="button"
                                                disabled={payGoods.processing}
                                                onClick={() => payGoods.post(route('admin.deliveries.pay-goods', stop.uuid))}
                                                className="mt-1 rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-brand-700 disabled:opacity-50"
                                            >
                                                {payGoods.processing ? 'Opening payment…' : 'Pay online before handover'}
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                        <div className="mt-2 flex gap-2">
                            <input
                                type="text"
                                inputMode="numeric"
                                autoFocus
                                maxLength={4}
                                value={deliver.data.delivery_code}
                                onChange={(event) =>
                                    deliver.setData(
                                        'delivery_code',
                                        event.target.value.replace(/\D/g, ''),
                                    )
                                }
                                placeholder="0000"
                                className="border w-28 rounded-lg border-emerald-300 text-center text-lg font-bold tracking-[0.4em] tabular-nums focus:border-emerald-500 focus:ring-emerald-500 px-3 py-2 shadow-sm"
                            />
                            <button
                                type="button"
                                disabled={
                                    deliver.processing || deliver.data.delivery_code.length !== 4 ||
                                    (stop.collectKobo > 0 && collectionMethod === 'courier_online' && !stop.goodsPaidAt)
                                }
                                onClick={() => {
                                    // transform() returns void in this version
                                    // of Inertia, so it cannot be chained.
                                    deliver.transform(() => ({
                                        delivery_code: deliver.data.delivery_code,
                                        collection_method: collectionMethod,
                                    }));

                                    deliver.post(route('admin.deliveries.deliver', stop.uuid), {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            deliver.reset();
                                            setHandingOver(false);
                                        },
                                    });
                                }}
                                className="flex-1 rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white transition hover:bg-emerald-700 disabled:opacity-40"
                            >
                                Confirm
                            </button>
                        </div>
                        {deliver.errors.delivery_code && (
                            <p className="mt-1.5 text-xs font-semibold text-red-600">
                                {deliver.errors.delivery_code}
                            </p>
                        )}
                        <button
                            type="button"
                            onClick={() => setHandingOver(false)}
                            className="mt-2 text-xs font-semibold text-emerald-800 underline"
                        >
                            Cancel
                        </button>
                    </div>
                )}

                {!reporting ? (
                    <button
                        type="button"
                        onClick={() => setReporting(true)}
                        className="flex w-full items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-semibold text-gray-500 transition hover:bg-gray-50"
                    >
                        Could not deliver <ChevronDown className="h-3.5 w-3.5" />
                    </button>
                ) : (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-3">
                        <p className="text-xs font-semibold text-amber-900">What happened?</p>
                        <div className="mt-2 space-y-1.5">
                            {failureReasons.map((reason) => (
                                <label
                                    key={reason.value}
                                    className="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm"
                                >
                                    <input
                                        type="radio"
                                        name={`outcome-${stop.uuid}`}
                                        value={reason.value}
                                        checked={fail.data.outcome === reason.value}
                                        onChange={() => fail.setData('outcome', reason.value)}
                                        className="text-amber-600 focus:ring-amber-500"
                                    />
                                    {reason.label}
                                </label>
                            ))}
                        </div>
                        <input
                            type="text"
                            value={fail.data.note}
                            onChange={(event) => fail.setData('note', event.target.value)}
                            placeholder="Anything the office should know (optional)"
                            maxLength={300}
                            className="border mt-2 w-full rounded-lg border-amber-300 text-sm focus:border-amber-500 focus:ring-amber-500 px-3 py-2 shadow-sm"
                        />
                        <div className="mt-2 flex gap-2">
                            <button
                                type="button"
                                onClick={() => setReporting(false)}
                                className="rounded-xl px-3 py-2 text-xs font-semibold text-amber-900"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={fail.processing || fail.data.outcome === ''}
                                onClick={() =>
                                    fail.post(route('admin.deliveries.fail', stop.uuid), {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            fail.reset();
                                            setReporting(false);
                                        },
                                    })
                                }
                                className="flex-1 rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-amber-700 disabled:opacity-40"
                            >
                                Record it
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
