import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Bike, KeyRound, Pencil, PauseCircle, PlayCircle, Plus, Search } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface CourierDetail {
    vehicleType: string;
    vehicleLabel: string;
    vehiclePlate: string | null;
    baseState: string | null;
    baseLga: string | null;
    maxOpenShipments: number;
    isAvailable: boolean;
    openCount: number;
}

interface StaffMember {
    uuid: string;
    name: string;
    email: string;
    phone: string | null;
    roles: string[];
    status: string;
    isCourier: boolean;
    courier: CourierDetail | null;
    joinedAt: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    staff: Paginated<StaffMember>;
    roles: string[];
    vehicleTypes: { value: string; label: string; hint: string }[];
    states: string[];
    query: string;
    role: string;
    [key: string]: unknown;
}

/**
 * Staff accounts.
 *
 * Until this screen there was no way to create one at all — every staff
 * account came from a seeder or a tinker session, so hiring a courier needed
 * a developer. Nobody sets anybody else's password here: the new staff member
 * is emailed a code and chooses their own.
 */
export default function StaffIndex() {
    const {
        staff = { data: [], links: [] },
        roles = [],
        vehicleTypes = [],
        states = [],
        query = '',
        role = '',
    } = usePage<Props>().props;

    const [editing, setEditing] = useState<StaffMember | null | undefined>(undefined);
    const [term, setTerm] = useState(query);

    const search = (next: { q?: string; role?: string }) =>
        router.get(
            route('admin.staff.index'),
            { q: next.q ?? term, role: next.role ?? role },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <AdminLayout>
            <Head title="Staff" />

            <PageHeader
                title="Staff"
                description="Couriers, support agents and finance officers. They set their own password from an emailed code."
                actions={
                    <button
                        type="button"
                        onClick={() => setEditing(null)}
                        className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700"
                    >
                        <Plus className="h-4 w-4" /> Add staff
                    </button>
                }
            />

            <div className="mb-4 grid gap-3 sm:grid-cols-[1fr_14rem] lg:max-w-2xl">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <Input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        onKeyDown={(event) => event.key === 'Enter' && search({})}
                        placeholder="Name, email or phone"
                        className="pl-9"
                    />
                </div>
                <Select value={role} onChange={(event) => search({ role: event.target.value })}>
                    <option value="">Every role</option>
                    {roles.map((name) => (
                        <option key={name} value={name}>
                            {name}
                        </option>
                    ))}
                </Select>
            </div>

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[48rem] text-left text-sm">
                        <thead className="border-b border-gray-100 bg-gray-50/60 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3 font-semibold">Name</th>
                                <th className="px-4 py-3 font-semibold">Role</th>
                                <th className="px-4 py-3 font-semibold">Contact</th>
                                <th className="px-4 py-3 font-semibold">Courier</th>
                                <th className="px-4 py-3 font-semibold">Status</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {staff.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12 text-center text-gray-400">
                                        No staff accounts yet.
                                    </td>
                                </tr>
                            )}

                            {staff.data.map((member) => (
                                <tr key={member.uuid} className="align-top hover:bg-gray-50/60">
                                    <td className="px-4 py-3">
                                        <p className="font-bold text-gray-900">{member.name}</p>
                                        <p className="text-xs text-gray-400">
                                            Since {member.joinedAt}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3">
                                        {member.roles.map((name) => (
                                            <span
                                                key={name}
                                                className="inline-block rounded-lg bg-gray-100 px-2 py-1 text-xs font-bold text-gray-700"
                                            >
                                                {name}
                                            </span>
                                        ))}
                                    </td>
                                    <td className="px-4 py-3 text-xs text-gray-600">
                                        <p>{member.email}</p>
                                        {member.phone && <p className="text-gray-400">{member.phone}</p>}
                                    </td>
                                    <td className="px-4 py-3 text-xs">
                                        {member.courier === null ? (
                                            <span className="text-gray-300">—</span>
                                        ) : (
                                            <>
                                                <p className="flex items-center gap-1 font-semibold text-gray-700">
                                                    <Bike className="h-3 w-3" />
                                                    {member.courier.vehicleLabel}
                                                    {member.courier.vehiclePlate &&
                                                        ` · ${member.courier.vehiclePlate}`}
                                                </p>
                                                <p className="text-gray-400">
                                                    {member.courier.openCount} carrying
                                                    {member.courier.baseState &&
                                                        ` · ${member.courier.baseState}`}
                                                </p>
                                                {!member.courier.isAvailable && (
                                                    <p className="font-semibold text-amber-700">
                                                        Off the dispatch list
                                                    </p>
                                                )}
                                            </>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span
                                            className={`inline-block rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ${
                                                member.status === 'active'
                                                    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                                    : 'bg-gray-100 text-gray-500 ring-gray-200'
                                            }`}
                                        >
                                            {member.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() => setEditing(member)}
                                                className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                                                aria-label={`Edit ${member.name}`}
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        route('admin.staff.resend-code', member.uuid),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                                                aria-label={`Resend password code to ${member.name}`}
                                                title="Email a new password code"
                                            >
                                                <KeyRound className="h-4 w-4" />
                                            </button>
                                            {/* A day off is not a disciplinary
                                                record, so availability and
                                                suspension are separate. */}
                                            {member.isCourier && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.post(
                                                            route(
                                                                'admin.staff.availability',
                                                                member.uuid,
                                                            ),
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                                                    aria-label={`Toggle dispatch availability for ${member.name}`}
                                                    title={
                                                        member.courier?.isAvailable
                                                            ? 'Take off the dispatch list'
                                                            : 'Put back on the dispatch list'
                                                    }
                                                >
                                                    {member.courier?.isAvailable ? (
                                                        <PauseCircle className="h-4 w-4" />
                                                    ) : (
                                                        <PlayCircle className="h-4 w-4" />
                                                    )}
                                                </button>
                                            )}
                                            {member.status === 'active' ? (
                                                <button
                                                    type="button"
                                                    onClick={() => suspend(member)}
                                                    className="rounded-lg px-2 py-1.5 text-xs font-semibold text-red-600 transition hover:bg-red-50"
                                                >
                                                    Suspend
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.post(
                                                            route(
                                                                'admin.staff.reactivate',
                                                                member.uuid,
                                                            ),
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    className="rounded-lg px-2 py-1.5 text-xs font-semibold text-emerald-600 transition hover:bg-emerald-50"
                                                >
                                                    Reactivate
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            {editing !== undefined && (
                <StaffForm
                    member={editing}
                    roles={roles}
                    vehicleTypes={vehicleTypes}
                    states={states}
                    onClose={() => setEditing(undefined)}
                />
            )}
        </AdminLayout>
    );
}

function suspend(member: StaffMember) {
    const reason = prompt(`Why is ${member.name} being suspended? (optional)`);

    // Cancelled the prompt rather than left it blank.
    if (reason === null) return;

    router.post(
        route('admin.staff.suspend', member.uuid),
        { reason },
        { preserveScroll: true },
    );
}

function StaffForm({
    member,
    roles,
    vehicleTypes,
    states,
    onClose,
}: {
    member: StaffMember | null;
    roles: string[];
    vehicleTypes: { value: string; label: string; hint: string }[];
    states: string[];
    onClose: () => void;
}) {
    const form = useForm({
        name: member?.name ?? '',
        email: member?.email ?? '',
        phone: member?.phone ?? '',
        role: member?.roles[0] ?? 'Logistics Personnel',
        vehicle_type: member?.courier?.vehicleType ?? 'motorcycle',
        vehicle_plate: member?.courier?.vehiclePlate ?? '',
        base_state: member?.courier?.baseState ?? '',
        base_lga: member?.courier?.baseLga ?? '',
        max_open_shipments: member?.courier?.maxOpenShipments ?? 0,
        is_available: member?.courier?.isAvailable ?? true,
    });

    const isCourier = form.data.role === 'Logistics Personnel';
    const vehicle = vehicleTypes.find((type) => type.value === form.data.vehicle_type);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (member) {
            form.put(route('admin.staff.update', member.uuid), options);
        } else {
            form.post(route('admin.staff.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={member ? `Edit ${member.name}` : 'Add a staff member'}
            description="They will get an email with a 6-digit code to set their own password."
            size="xl"
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">
                            Full name
                        </span>
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <InputError message={form.errors.name} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Role</span>
                        <Select
                            value={form.data.role}
                            onChange={(event) => form.setData('role', event.target.value)}
                        >
                            {roles.map((name) => (
                                <option key={name} value={name}>
                                    {name}
                                </option>
                            ))}
                        </Select>
                        <InputError message={form.errors.role} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Email</span>
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                        <InputError message={form.errors.email} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">
                            Phone {isCourier && <span className="text-red-500">*</span>}
                        </span>
                        <Input
                            value={form.data.phone}
                            onChange={(event) => form.setData('phone', event.target.value)}
                            placeholder="08031234567"
                        />
                        {isCourier && (
                            <p className="mt-1 text-[11px] text-gray-400">
                                Customers and the dispatch desk both call it.
                            </p>
                        )}
                        <InputError message={form.errors.phone} className="mt-1" />
                    </label>
                </div>

                {isCourier && (
                    <div className="space-y-3 rounded-xl border border-gray-100 p-4">
                        <p className="text-xs font-bold uppercase tracking-wide text-gray-500">
                            Courier details
                        </p>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block">
                                <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                    Vehicle
                                </span>
                                <Select
                                    value={form.data.vehicle_type}
                                    onChange={(event) =>
                                        form.setData('vehicle_type', event.target.value)
                                    }
                                >
                                    {vehicleTypes.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </Select>
                                {/* The commonest dispatch mistake is sending a
                                    motorcycle for a fridge. */}
                                {vehicle && (
                                    <p className="mt-1 text-[11px] text-gray-400">{vehicle.hint}</p>
                                )}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                    Plate number
                                </span>
                                <Input
                                    value={form.data.vehicle_plate}
                                    onChange={(event) =>
                                        form.setData('vehicle_plate', event.target.value.toUpperCase())
                                    }
                                    placeholder="ABC 123 XY"
                                    className="uppercase"
                                />
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                    Usually works in
                                </span>
                                <Select
                                    value={form.data.base_state}
                                    onChange={(event) =>
                                        form.setData('base_state', event.target.value)
                                    }
                                >
                                    <option value="">Anywhere</option>
                                    {states.map((state) => (
                                        <option key={state} value={state}>
                                            {state}
                                        </option>
                                    ))}
                                </Select>
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                    Parcels at once — 0 for no limit
                                </span>
                                <Input
                                    type="number"
                                    min={0}
                                    value={form.data.max_open_shipments}
                                    onChange={(event) =>
                                        form.setData(
                                            'max_open_shipments',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                                <p className="mt-1 text-[11px] text-gray-400">
                                    A guide for the dispatch desk, not a hard stop.
                                </p>
                            </label>
                        </div>
                    </div>
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl px-4 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 disabled:opacity-50"
                    >
                        {member ? 'Save changes' : 'Add and send code'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
