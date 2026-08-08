import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Bike, MapPin, Package, Truck, Undo2 } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Waiting {
    uuid: string;
    contents: string;
    unitCount: number;
    vendorName: string;
    destination: string;
    state: string;
    status: string;
    statusLabel: string;
    attemptCount: number;
    isException: boolean;
    waitingDays: number;
}

interface InFlight {
    uuid: string;
    vendorName: string;
    destination: string;
    statusLabel: string;
    courierName: string | null;
    canForceDeliver: boolean;
    waitingDays: number;
}

interface Courier {
    id: number;
    name: string;
    vehicle: string;
    capacityHint: string;
    baseState: string | null;
    openCount: number;
    maxOpen: number | null;
    isOverloaded: boolean;
}

interface Props {
    waiting: Waiting[];
    exceptions: Waiting[];
    inFlight: InFlight[];
    couriers: Courier[];
    states: string[];
    filters: { state: string | null; vendor: number | null };
    vendorOptions: { id: number; name: string }[];
    [key: string]: unknown;
}

/**
 * The dispatch desk.
 *
 * Assignment used to live only inside an order, one at a time, so forty ready
 * orders were forty page loads. This is the queue: filter to a state, tick
 * everything going the same way, send it to one courier.
 *
 * Exceptions sit at the top rather than behind a tab. A parcel that has
 * failed three times is waiting on a human decision, and a decision nobody is
 * shown is a decision nobody makes.
 */
export default function Dispatch() {
    const {
        waiting = [],
        exceptions = [],
        inFlight = [],
        couriers = [],
        states = [],
        filters = { state: null, vendor: null },
        vendorOptions = [],
    } = usePage<Props>().props;

    const [selected, setSelected] = useState<string[]>([]);
    const [courierId, setCourierId] = useState<number | ''>('');
    const [forcing, setForcing] = useState<InFlight | null>(null);

    const chosen = useMemo(
        () => couriers.find((courier) => courier.id === courierId) ?? null,
        [couriers, courierId],
    );

    const toggle = (uuid: string, on: boolean) =>
        setSelected((current) =>
            on ? [...current, uuid] : current.filter((item) => item !== uuid),
        );

    const filter = (key: 'state' | 'vendor', value: string) =>
        router.get(
            route('admin.dispatch.index'),
            {
                state: key === 'state' ? value : (filters.state ?? ''),
                vendor: key === 'vendor' ? value : (filters.vendor ?? ''),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const assign = () =>
        router.post(
            route('admin.dispatch.assign'),
            { uuids: selected, courier_id: courierId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    setCourierId('');
                },
            },
        );

    return (
        <AdminLayout>
            <Head title="Dispatch" />

            <PageHeader
                title="Dispatch"
                description="Parcels waiting for a courier. One pickup and one doorstep per row, however many items are in the box."
            />

            {/* ── Exceptions ── */}
            {exceptions.length > 0 && (
                <Card className="mb-4 border-red-200 bg-red-50/60 p-4">
                    <h2 className="flex items-center gap-2 text-sm font-extrabold text-red-800">
                        <AlertTriangle className="h-4 w-4" />
                        {exceptions.length} parcel{exceptions.length === 1 ? '' : 's'} out of retries
                    </h2>
                    <p className="mt-1 text-xs leading-relaxed text-red-700">
                        These have been attempted three times. Another trip will not fix them —
                        call the customer, correct the address, or cancel the order and refund it.
                    </p>
                    <ul className="mt-3 space-y-1.5">
                        {exceptions.map((parcel) => (
                            <li
                                key={parcel.uuid}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-3 py-2 text-sm"
                            >
                                <span className="min-w-0">
                                    <span className="font-bold text-gray-900">{parcel.contents}</span>
                                    <span className="ml-2 text-xs text-gray-500">
                                        {parcel.vendorName} → {parcel.destination}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xs font-bold text-red-700">
                                    {parcel.attemptCount} failed attempts
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {/* ── Filters ── */}
            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:max-w-xl">
                <label className="block">
                    <span className="mb-1 block text-xs font-semibold text-gray-500">State</span>
                    <Select
                        value={filters.state ?? ''}
                        onChange={(event) => filter('state', event.target.value)}
                    >
                        <option value="">Everywhere</option>
                        {states.map((state) => (
                            <option key={state} value={state}>
                                {state}
                            </option>
                        ))}
                    </Select>
                </label>
                <label className="block">
                    <span className="mb-1 block text-xs font-semibold text-gray-500">Vendor</span>
                    <Select
                        value={filters.vendor ?? ''}
                        onChange={(event) => filter('vendor', event.target.value)}
                    >
                        <option value="">Every vendor</option>
                        {vendorOptions.map((vendor) => (
                            <option key={vendor.id} value={vendor.id}>
                                {vendor.name}
                            </option>
                        ))}
                    </Select>
                </label>
            </div>

            <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
                {/* ── The queue ── */}
                <Card className="overflow-hidden">
                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h2 className="text-sm font-bold text-gray-900">
                            Waiting for a courier ({waiting.length})
                        </h2>
                        {waiting.length > 0 && (
                            <label className="flex items-center gap-2 text-xs font-semibold text-gray-600">
                                <input
                                    type="checkbox"
                                    checked={selected.length === waiting.length}
                                    onChange={(event) =>
                                        setSelected(
                                            event.target.checked
                                                ? waiting.map((parcel) => parcel.uuid)
                                                : [],
                                        )
                                    }
                                    className="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                />
                                Select all
                            </label>
                        )}
                    </div>

                    {waiting.length === 0 ? (
                        <p className="px-4 py-14 text-center text-sm text-gray-400">
                            Nothing waiting. Parcels appear here once every item in them is packed.
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {waiting.map((parcel) => (
                                <li key={parcel.uuid}>
                                    <label className="flex cursor-pointer items-start gap-3 px-4 py-3 hover:bg-gray-50/60">
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(parcel.uuid)}
                                            onChange={(event) =>
                                                toggle(parcel.uuid, event.target.checked)
                                            }
                                            className="mt-1 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="font-bold text-gray-900">
                                                    {parcel.contents}
                                                </span>
                                                <span className="inline-flex items-center gap-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-bold text-gray-600">
                                                    <Package className="h-3 w-3" />
                                                    {parcel.unitCount}
                                                </span>
                                                {parcel.attemptCount > 0 && (
                                                    <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-bold text-amber-700">
                                                        {parcel.attemptCount} failed
                                                    </span>
                                                )}
                                                {parcel.waitingDays >= 3 && (
                                                    <span className="rounded bg-red-50 px-1.5 py-0.5 text-[11px] font-bold text-red-700">
                                                        {parcel.waitingDays}d old
                                                    </span>
                                                )}
                                            </span>
                                            <span className="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500">
                                                <MapPin className="h-3 w-3 shrink-0" />
                                                {parcel.vendorName} → {parcel.destination}
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                {/* ── Couriers ── */}
                <div className="space-y-4">
                    <Card className="p-4">
                        <h2 className="text-sm font-bold text-gray-900">
                            Assign {selected.length > 0 && `${selected.length} parcel${selected.length === 1 ? '' : 's'}`}
                        </h2>

                        {couriers.length === 0 ? (
                            <p className="mt-2 text-xs leading-relaxed text-gray-500">
                                No couriers available. Add one under{' '}
                                <a
                                    href={route('admin.staff.index')}
                                    className="font-semibold text-brand-600 underline"
                                >
                                    Staff
                                </a>
                                .
                            </p>
                        ) : (
                            <>
                                <div className="mt-3 space-y-1.5">
                                    {couriers.map((courier) => (
                                        <label
                                            key={courier.id}
                                            className={`flex cursor-pointer items-start gap-2.5 rounded-xl border px-3 py-2.5 transition ${
                                                courierId === courier.id
                                                    ? 'border-brand-400 bg-brand-50'
                                                    : 'border-gray-100 hover:bg-gray-50'
                                            }`}
                                        >
                                            <input
                                                type="radio"
                                                name="courier"
                                                checked={courierId === courier.id}
                                                onChange={() => setCourierId(courier.id)}
                                                className="mt-1 text-brand-600 focus:ring-brand-500"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-bold text-gray-900">
                                                    {courier.name}
                                                </span>
                                                <span className="flex items-center gap-1 text-[11px] text-gray-500">
                                                    <Bike className="h-3 w-3 shrink-0" />
                                                    {courier.vehicle}
                                                    {courier.baseState && ` · ${courier.baseState}`}
                                                </span>
                                                <span
                                                    className={`text-[11px] font-semibold ${
                                                        courier.isOverloaded
                                                            ? 'text-amber-700'
                                                            : 'text-gray-400'
                                                    }`}
                                                >
                                                    {courier.openCount} carrying
                                                    {courier.maxOpen && ` of ${courier.maxOpen}`}
                                                    {courier.isOverloaded && ' — at capacity'}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>

                                {/* A ceiling is a default for a normal day. A
                                    dispatcher covering for someone off sick has
                                    to be able to go past it, so this warns and
                                    the button stays live. */}
                                {chosen?.isOverloaded && (
                                    <p className="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-800">
                                        {chosen.name} is already at their usual limit. You can still
                                        assign — this is a guide, not a rule.
                                    </p>
                                )}

                                {chosen && (
                                    <p className="mt-2 text-[11px] text-gray-400">
                                        {chosen.capacityHint}
                                    </p>
                                )}

                                <button
                                    type="button"
                                    disabled={selected.length === 0 || courierId === ''}
                                    onClick={assign}
                                    className="mt-3 w-full rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 disabled:opacity-30"
                                >
                                    Assign
                                </button>
                            </>
                        )}
                    </Card>

                    {/* ── Out with couriers ── */}
                    <Card className="p-4">
                        <h2 className="flex items-center gap-1.5 text-sm font-bold text-gray-900">
                            <Truck className="h-4 w-4 text-gray-400" /> On the road ({inFlight.length})
                        </h2>

                        {inFlight.length === 0 ? (
                            <p className="mt-2 text-xs text-gray-400">Nothing out right now.</p>
                        ) : (
                            <ul className="mt-2 space-y-2">
                                {inFlight.map((parcel) => (
                                    <li key={parcel.uuid} className="rounded-xl bg-gray-50 px-3 py-2">
                                        <p className="truncate text-xs font-bold text-gray-800">
                                            {parcel.courierName ?? 'Unassigned'}
                                        </p>
                                        <p className="truncate text-[11px] text-gray-500">
                                            {parcel.destination} · {parcel.statusLabel}
                                        </p>
                                        <div className="mt-1.5 flex gap-2">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        route('admin.dispatch.recall', parcel.uuid),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-600 hover:text-gray-900"
                                            >
                                                <Undo2 className="h-3 w-3" /> Recall
                                            </button>
                                            {parcel.canForceDeliver && (
                                                <button
                                                    type="button"
                                                    onClick={() => setForcing(parcel)}
                                                    className="text-[11px] font-semibold text-amber-700 hover:text-amber-900"
                                                >
                                                    Close without code
                                                </button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            {forcing && <ForceDeliverDialog parcel={forcing} onClose={() => setForcing(null)} />}
        </AdminLayout>
    );
}

/**
 * Closing a delivery without the customer's code.
 *
 * The escape hatch that makes the code workable — a customer who lost it
 * still needs their parcel. Demands a written reason because the override is
 * permanently stamped on the shipment, and "delivered without proof" must
 * always be answerable rather than merely indistinguishable.
 */
function ForceDeliverDialog({ parcel, onClose }: { parcel: InFlight; onClose: () => void }) {
    const form = useForm({ reason: '' });

    return (
        <Modal
            open
            onClose={onClose}
            title="Close without the code"
            description="Only when the customer cannot give it — this override goes on the record."
            size="md"
        >
            <div className="space-y-4">
                <p className="rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-600">
                    {parcel.destination} · carried by {parcel.courierName ?? 'nobody'}
                </p>

                <label className="block">
                    <span className="mb-1 block text-xs font-semibold text-gray-600">
                        Why was the code not used?
                    </span>
                    <textarea
                        rows={3}
                        autoFocus
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        placeholder="e.g. Customer confirmed receipt by phone; could not find the code in their email."
                        maxLength={300}
                        className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                    <InputError message={form.errors.reason} className="mt-1" />
                </label>

                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={form.processing || form.data.reason.trim().length < 5}
                        onClick={() =>
                            form.post(route('admin.dispatch.force-deliver', parcel.uuid), {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                        className="rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-amber-700 disabled:opacity-40"
                    >
                        Close as delivered
                    </button>
                </div>
            </div>
        </Modal>
    );
}
