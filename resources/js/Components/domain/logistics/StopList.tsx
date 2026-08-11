import StopCard, { FailureReason, Stop } from '@/Components/domain/logistics/StopCard';
import { router } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import { useState } from 'react';

interface Props {
    stops: Stop[];
    failureReasons: FailureReason[];
}

/**
 * A courier's round, with a bulk "move these on" bar.
 *
 * Bulk exists because the real motion is collecting eight parcels from one
 * vendor at once, and tapping eight buttons for one act of loading a van is
 * the kind of friction that gets skipped and then back-filled dishonestly at
 * the end of the day.
 *
 * Handing over is never bulk. It needs the customer's code, one door at a
 * time — which is the whole point of the code.
 */
export default function StopList({ stops, failureReasons }: Props) {
    const [selected, setSelected] = useState<string[]>([]);

    const selectable = stops.filter((stop) => stop.nextStep !== null);

    const toggle = (uuid: string, on: boolean) =>
        setSelected((current) =>
            on ? [...current, uuid] : current.filter((item) => item !== uuid),
        );

    const advanceSelected = () =>
        router.post(
            route('admin.deliveries.bulk-advance'),
            { uuids: selected },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );

    if (stops.length === 0) {
        return (
            <div className="rounded-2xl border border-dashed border-gray-200 bg-white px-6 py-16 text-center">
                <PackageCheck className="mx-auto h-10 w-10 text-gray-300" />
                <p className="mt-3 font-bold text-gray-700">Nothing to carry right now.</p>
                <p className="mt-1 text-sm text-gray-500">
                    New parcels appear here as soon as the office assigns them to you.
                </p>
            </div>
        );
    }

    return (
        <>
            {selectable.length > 1 && (
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-gray-50 px-3 py-2.5">
                    <label className="flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <input
                            type="checkbox"
                            checked={selected.length === selectable.length}
                            onChange={(event) =>
                                setSelected(
                                    event.target.checked
                                        ? selectable.map((stop) => stop.uuid)
                                        : [],
                                )
                            }
                            className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                        />
                        Select all ({selectable.length})
                    </label>

                    <button
                        type="button"
                        disabled={selected.length === 0}
                        onClick={advanceSelected}
                        className="rounded-xl bg-gray-900 px-4 py-2 text-sm font-bold text-white transition hover:bg-gray-800 disabled:opacity-30"
                    >
                        Move {selected.length || ''} on
                    </button>
                </div>
            )}

            <div className="space-y-3">
                {stops.map((stop) => (
                    <StopCard
                        key={stop.uuid}
                        stop={stop}
                        failureReasons={failureReasons}
                        selected={selected.includes(stop.uuid)}
                        onSelect={toggle}
                    />
                ))}
            </div>
        </>
    );
}
