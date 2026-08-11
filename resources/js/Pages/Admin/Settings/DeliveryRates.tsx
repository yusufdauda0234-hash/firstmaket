import TemplatePicker, { Template } from '@/Components/domain/admin/TemplatePicker';
import BulkActionBar from '@/Components/ui/BulkActionBar';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import { MoneyInput } from '@/Components/ui/MoneyInput';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import { Select } from '@/Components/ui/Select';
import ViewToggle from '@/Components/ui/ViewToggle';
import AdminLayout from '@/Layouts/AdminLayout';
import { useRowSelection } from '@/Hooks/useRowSelection';
import { useViewMode } from '@/Hooks/useViewMode';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Truck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Rate {
    uuid: string;
    state: string | null;
    isDefault: boolean;
    feeNaira: number;
    /** Same as feeNaira. Kept so the table markup did not need rewriting. */
    totalNaira: number;
    freeThresholdNaira: number | null;
    /** The threshold actually in force for this row. */
    effectiveFreeThresholdNaira: number;
    isActive: boolean;
    note: string | null;
    updatedBy: string | null;
    updatedAt: string;
}

interface Props {
    /** Ready-made settings an admin can apply in one click. */
    templates: Template[];
    rates: Rate[];
    availableStates: string[];
    hasDefault: boolean;
    [key: string]: unknown;
}

const naira = new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 0,
});

/**
 * What delivery costs, per state.
 *
 * One fee per destination. It used to be kept as two legs — collecting from
 * the vendor, then delivering to the door — but nobody priced it that way:
 * both were always edited together and the customer only ever saw the sum,
 * so it was two boxes for one number.
 */
export default function DeliveryRates() {
    const { rates, availableStates, hasDefault, templates = [] } = usePage<Props>().props;

    const { mode, choose } = useViewMode('admin.delivery-rates', 'table');
    const selection = useRowSelection(rates.map((rate) => rate.uuid));
    const bulk = useForm<{ action: string; uuids: string[] }>({ action: 'activate', uuids: [] });

    const [editing, setEditing] = useState<Rate | null>(null);
    const [adding, setAdding] = useState(false);

    function runBulk(action: 'activate' | 'deactivate') {
        bulk.transform(() => ({ action, uuids: selection.ids }));
        bulk.post(route('admin.settings.delivery-rates.bulk'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    function remove(rate: Rate) {
        if (!confirm(`Delete the delivery rate for ${rate.state}? That state will use the default.`)) return;

        router.delete(route('admin.settings.delivery-rates.destroy', rate.uuid), { preserveScroll: true });
    }

    return (
        <AdminLayout>
            <Head title="Delivery rates" />

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-extrabold tracking-tight text-gray-900">Delivery rates</h1>
                    <p className="mt-1 max-w-2xl text-sm text-gray-500">
                        What a customer pays to have an order delivered, by where it is going.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <ViewToggle mode={mode} onChange={choose} label="rates" />
                    <TemplatePicker
                        templates={templates}
                        action={route('admin.settings.delivery-rates.template')}
                        noun="delivery rates"
                        empty={rates.length === 0}
                    />
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                    >
                        <Plus className="h-4 w-4" /> Add a rate
                    </button>
                </div>
            </div>

            {/* There is no fallback fee anywhere in the code any more — these
                rows are the only source. So "no default" does not mean some
                other figure applies, it means delivery is free, which is
                almost never what anyone intended. */}
            {!hasDefault && (
                <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <strong className="font-bold">No default rate — those orders ship free.</strong> Every
                    state without its own row below is being charged nothing to deliver, on the storefront and
                    on Pay Small Small plans alike. Add a rate and leave the state blank to set what the rest
                    of the country pays.
                </div>
            )}

            {rates.length === 0 ? (
                <div className="flex flex-col items-center rounded-2xl border border-dashed border-gray-200 bg-white px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Truck className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No delivery rates yet</p>
                    <p className="mt-1 max-w-md text-sm text-gray-500">
                        Until one is added, every order ships free — these rows are the only thing that sets a
                        delivery fee anywhere in FirstMaket.
                    </p>
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="mt-4 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                    >
                        + Add the default rate
                    </button>
                </div>
            ) : mode === 'table' ? (
                <div className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px] text-sm">
                            <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="w-10 py-3 pl-5 pr-2">
                                        <RowCheckbox
                                            checked={selection.allSelected}
                                            indeterminate={selection.someSelected}
                                            onChange={selection.toggleAll}
                                            label="Select all rates"
                                        />
                                    </th>
                                    <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                    <th className="px-5 py-3 font-semibold">State</th>
                                    <th className="px-4 py-3 text-right font-semibold">Customer pays</th>
                                    <th className="px-4 py-3 text-right font-semibold">Free over</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {rates.map((rate, index) => (
                                    <tr key={rate.uuid} className="transition-colors hover:bg-slate-50/60">
                                        <td className="py-3 pl-5 pr-2">
                                            <RowCheckbox
                                                checked={selection.isSelected(rate.uuid)}
                                                onChange={() => selection.toggle(rate.uuid)}
                                                label={`Select ${rate.state ?? 'default'}`}
                                            />
                                        </td>
                                        <td className="px-2 py-3 text-xs tabular-nums text-gray-400">
                                            {index + 1}
                                        </td>
                                        <td className="px-5 py-3">
                                            <span className="font-semibold text-gray-900">
                                                {rate.state ?? 'Everywhere else'}
                                            </span>
                                            {rate.isDefault && (
                                                <span className="ml-2 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-bold text-brand-700">
                                                    Default
                                                </span>
                                            )}
                                            {rate.note && (
                                                <span className="block text-xs text-gray-400">{rate.note}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right font-bold tabular-nums text-gray-900">
                                            {naira.format(rate.totalNaira)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-gray-600">
                                            {rate.effectiveFreeThresholdNaira === 0 ? (
                                                <span className="font-semibold text-emerald-700">
                                                    always charged
                                                </span>
                                            ) : (
                                                <>
                                                    {naira.format(rate.effectiveFreeThresholdNaira)}
                                                </>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${
                                                    rate.isActive
                                                        ? 'bg-emerald-50 text-emerald-700'
                                                        : 'bg-gray-100 text-gray-500'
                                                }`}
                                            >
                                                {rate.isActive ? 'Live' : 'Off'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3 text-right">
                                            <span className="inline-flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => setEditing(rate)}
                                                    aria-label={`Edit ${rate.state ?? 'default'}`}
                                                    className="rounded-lg p-1.5 text-gray-400 transition hover:bg-brand-50 hover:text-brand-700"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                {!rate.isDefault && (
                                                    <button
                                                        type="button"
                                                        onClick={() => remove(rate)}
                                                        aria-label={`Delete ${rate.state}`}
                                                        className="rounded-lg p-1.5 text-gray-300 transition hover:bg-red-50 hover:text-red-600"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {rates.map((rate) => (
                        <div
                            key={rate.uuid}
                            className="flex flex-col rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"
                        >
                            <span className="flex items-center justify-between gap-2">
                                <span className="font-bold text-gray-900">
                                    {rate.state ?? 'Everywhere else'}
                                </span>
                                <span
                                    className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${
                                        rate.isActive
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-gray-100 text-gray-500'
                                    }`}
                                >
                                    {rate.isActive ? 'Live' : 'Off'}
                                </span>
                            </span>

                            <span className="mt-3 text-2xl font-extrabold tracking-tight text-gray-900">
                                {naira.format(rate.totalNaira)}
                            </span>
                            <span className="text-xs text-gray-400">what the customer pays</span>

                            <dl className="mt-3 space-y-1 border-t border-gray-100 pt-2.5 text-xs">
                                <div className="flex justify-between">
                                    <dt className="text-gray-500">Free over</dt>
                                    <dd className="tabular-nums text-gray-700">
                                        {rate.effectiveFreeThresholdNaira === 0
                                            ? 'never'
                                            : naira.format(rate.effectiveFreeThresholdNaira)}
                                    </dd>
                                </div>
                            </dl>

                            <div className="mt-3 flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => setEditing(rate)}
                                    className="flex-1 rounded-full border border-gray-200 py-1.5 text-xs font-bold text-gray-600 transition hover:border-brand-300 hover:text-brand-700"
                                >
                                    Edit
                                </button>
                                {!rate.isDefault && (
                                    <button
                                        type="button"
                                        onClick={() => remove(rate)}
                                        className="rounded-full border border-gray-200 px-3 py-1.5 text-xs font-bold text-gray-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                                    >
                                        Delete
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <BulkActionBar
                count={selection.ids.length}
                noun="rate"
                onClear={selection.clear}
                processing={bulk.processing}
                actions={[
                    { label: 'Switch on', run: () => runBulk('activate') },
                    { label: 'Switch off', tone: 'danger', run: () => runBulk('deactivate') },
                ]}
            />

            {(adding || editing) && (
                <RateForm
                    rate={editing}
                    availableStates={availableStates}
                    hasDefault={hasDefault}
                    onDone={() => {
                        setAdding(false);
                        setEditing(null);
                    }}
                />
            )}
        </AdminLayout>
    );
}

function RateForm({
    rate,
    availableStates,
    hasDefault,
    onDone,
}: {
    rate: Rate | null;
    availableStates: string[];
    hasDefault: boolean;
    onDone: () => void;
}) {
    const form = useForm({
        state: rate?.state ?? '',
        fee_naira: rate?.feeNaira ?? 0,
        free_threshold_naira: rate?.freeThresholdNaira ?? ('' as number | ''),
        is_active: rate?.isActive ?? true,
        note: rate?.note ?? '',
    });

    const total = Number(form.data.fee_naira) || 0;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onDone() };

        if (rate) {
            form.put(route('admin.settings.delivery-rates.update', rate.uuid), options);
        } else {
            form.post(route('admin.settings.delivery-rates.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onDone}
            title={rate ? `Edit ${rate.state ?? 'the default rate'}` : 'Add a delivery rate'}
            description="Both legs together are what the customer is quoted at checkout."
            size="xl"
            footer={
                <>
                    <button
                        type="button"
                        onClick={onDone}
                        className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="delivery-rate-form"
                        disabled={form.processing}
                        className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:bg-gray-200 disabled:text-gray-400"
                    >
                        {form.processing ? 'Saving…' : 'Save rate'}
                    </button>
                </>
            }
        >
            <form id="delivery-rate-form" onSubmit={submit} className="space-y-4">
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-gray-700">State</span>
                    <Select
                        value={form.data.state}
                        onChange={(e) => form.setData('state', e.target.value)}
                        disabled={rate !== null}
                    >
                        {/* The blank option is the default row, and only
                            offered when there is not already one. */}
                        {(!hasDefault || rate?.isDefault) && (
                            <option value="">Everywhere else (the default rate)</option>
                        )}
                        {rate?.state && <option value={rate.state}>{rate.state}</option>}
                        {availableStates.map((state) => (
                            <option key={state} value={state}>
                                {state}
                            </option>
                        ))}
                    </Select>
                    <p className="mt-1 text-xs text-gray-400">
                        {rate
                            ? 'A rate cannot be moved to another state — delete it and add one instead.'
                            : 'Leave as "Everywhere else" for the rate every unlisted state falls back to.'}
                    </p>
                    <InputError message={form.errors.state} className="mt-1" />
                </label>

                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-gray-700">
                        Delivery fee <span className="font-normal text-gray-400">₦</span>
                    </span>
                    <MoneyInput
                        min={0}
                        value={form.data.fee_naira}
                        onChange={(value: number | '') =>
                            form.setData('fee_naira', value === '' ? 0 : value)
                        }
                    />
                    <p className="mt-1 text-xs text-gray-400">
                        What a customer here pays to have an order delivered.
                    </p>
                    <InputError message={form.errors.fee_naira} className="mt-1" />
                </label>

                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-gray-700">
                        Free delivery over <span className="font-normal text-gray-400">₦, 0 for never</span>
                    </span>
                    <MoneyInput
                        min={0}
                        value={form.data.free_threshold_naira}
                        onChange={(value: number | '') => form.setData('free_threshold_naira', value)}
                    />
                    {/* This used to say blank "inherits the default threshold".
                        It does not, and cannot: the column is NOT NULL and a
                        blank posts as 0. A state row's threshold is its own,
                        full stop — so the copy now says what actually happens
                        rather than describing a fallback that was removed with
                        the config one. */}
                    <p className="mt-1 text-xs text-gray-400">
                        Orders at or above this are delivered <strong>free</strong>. Leave it{' '}
                        <strong>0</strong> to charge the fee on every order, however large.
                    </p>
                    {Number(form.data.free_threshold_naira) > 0 && (
                        <p className="mt-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Anything from {naira.format(Number(form.data.free_threshold_naira))} up ships free —
                            the {naira.format(total)} is only charged below that.
                        </p>
                    )}
                    <InputError message={form.errors.free_threshold_naira} className="mt-1" />
                </label>

                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-gray-700">
                        Note <span className="font-normal text-gray-400">optional, staff only</span>
                    </span>
                    <input
                        type="text"
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        maxLength={200}
                        placeholder="e.g. Renegotiated with the courier in August"
                        className="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                    />
                    <InputError message={form.errors.note} className="mt-1" />
                </label>

                <label className="flex items-center gap-2.5">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                        className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                    />
                    <span className="text-sm text-gray-700">Use this rate at checkout</span>
                </label>
            </form>
        </Modal>
    );
}
