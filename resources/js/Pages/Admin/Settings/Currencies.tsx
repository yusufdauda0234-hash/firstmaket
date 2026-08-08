import TemplatePicker, { Template } from '@/Components/domain/admin/TemplatePicker';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Coins, Pencil, Plus, Trash2, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Currency {
    id: number;
    code: string;
    symbol: string;
    name: string;
    unitsPerNaira: number;
    nairaPerUnit: number | null;
    decimals: number;
    isActive: boolean;
    isBase: boolean;
    sortOrder: number;
    updatedAt: string | null;
    updatedAgoDays: number;
}

interface Props extends PageProps {
    /** Ready-made settings an admin can apply in one click. */
    templates: Template[];
    currencies: Currency[];
}

/** A rate nobody has looked at in this long is quoting shoppers stale prices. */
const STALE_AFTER_DAYS = 30;

const blank = {
    code: '',
    symbol: '',
    name: '',
    units_per_naira: '' as number | '',
    decimals: 2,
    is_active: true,
};

export default function Currencies() {
    const { currencies, templates = [] } = usePage<Props>().props;
    const [editing, setEditing] = useState<Currency | null>(null);
    const [creating, setCreating] = useState(false);

    const form = useForm({ ...blank });

    function openCreate() {
        form.setData({ ...blank });
        form.clearErrors();
        setEditing(null);
        setCreating(true);
    }

    function openEdit(currency: Currency) {
        form.setData({
            code: currency.code,
            symbol: currency.symbol,
            name: currency.name,
            units_per_naira: currency.unitsPerNaira,
            decimals: currency.decimals,
            is_active: currency.isActive,
        });
        form.clearErrors();
        setCreating(false);
        setEditing(currency);
    }

    function close() {
        setCreating(false);
        setEditing(null);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            form.put(route('admin.settings.currencies.update', editing.id), {
                preserveScroll: true,
                onSuccess: close,
            });
        } else {
            form.post(route('admin.settings.currencies.store'), {
                preserveScroll: true,
                onSuccess: close,
            });
        }
    };

    const stale = currencies.filter((c) => !c.isBase && c.isActive && c.updatedAgoDays >= STALE_AFTER_DAYS);

    return (
        <AdminLayout>
            <Head title="Display currencies" />

            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold text-gray-900">
                        <Coins className="h-6 w-6 text-brand-600" /> Display currencies
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm text-gray-500">
                        What shoppers can browse in. Prices are stored and charged in naira — these rates only
                        change what a customer sees while browsing, and the naira total is always shown on the
                        pay button.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <TemplatePicker
                        templates={templates}
                        action={route('admin.settings.currencies.template')}
                        noun="currencies"
                        empty={currencies.length === 0}
                    />
                    <button
                        type="button"
                        onClick={openCreate}
                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700"
                    >
                        <Plus className="h-4 w-4" /> Add currency
                    </button>
                </div>
            </div>

            {stale.length > 0 && (
                <div className="mt-5 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                    <p className="text-sm text-amber-900">
                        <span className="font-bold">
                            {stale.length} rate{stale.length === 1 ? '' : 's'} not reviewed in over{' '}
                            {STALE_AFTER_DAYS} days
                        </span>{' '}
                        — {stale.map((c) => c.code).join(', ')}. Shoppers are being quoted these figures right
                        now. Update them, or switch them off until you can.
                    </p>
                </div>
            )}

            <div className="mt-5 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[820px] text-sm">
                        <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-5 py-3 font-semibold">Currency</th>
                                <th className="px-5 py-3 font-semibold">Rate</th>
                                <th className="px-5 py-3 font-semibold">Decimals</th>
                                <th className="px-5 py-3 font-semibold">Reviewed</th>
                                <th className="px-5 py-3 font-semibold">Status</th>
                                <th className="px-5 py-3 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {currencies.map((currency) => (
                                <tr key={currency.id} className="hover:bg-gray-50/60">
                                    <td className="px-5 py-4">
                                        <span className="font-bold text-gray-900">
                                            {currency.symbol} {currency.code}
                                        </span>
                                        <span className="block text-xs text-gray-400">{currency.name}</span>
                                    </td>
                                    <td className="px-5 py-4 tabular-nums">
                                        {currency.isBase ? (
                                            <span className="text-gray-400">base currency</span>
                                        ) : (
                                            <>
                                                <span className="font-medium text-gray-900">
                                                    ₦1 = {currency.unitsPerNaira} {currency.code}
                                                </span>
                                                {currency.nairaPerUnit !== null && (
                                                    <span className="block text-xs text-gray-400">
                                                        ₦{currency.nairaPerUnit.toLocaleString()} to 1{' '}
                                                        {currency.code}
                                                    </span>
                                                )}
                                            </>
                                        )}
                                    </td>
                                    <td className="px-5 py-4 tabular-nums text-gray-600">{currency.decimals}</td>
                                    <td className="px-5 py-4 text-gray-500">
                                        <span
                                            className={
                                                !currency.isBase &&
                                                currency.isActive &&
                                                currency.updatedAgoDays >= STALE_AFTER_DAYS
                                                    ? 'font-semibold text-amber-700'
                                                    : ''
                                            }
                                        >
                                            {currency.updatedAt ?? '—'}
                                        </span>
                                    </td>
                                    <td className="px-5 py-4">
                                        <span
                                            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${
                                                currency.isActive
                                                    ? 'bg-emerald-50 text-emerald-700'
                                                    : 'bg-gray-100 text-gray-500'
                                            }`}
                                        >
                                            {currency.isActive ? 'Shown to shoppers' : 'Hidden'}
                                        </span>
                                    </td>
                                    <td className="px-5 py-4">
                                        <div className="flex items-center justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() => openEdit(currency)}
                                                aria-label={`Edit ${currency.code}`}
                                                className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </button>
                                            {!currency.isBase && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        if (
                                                            confirm(
                                                                `Remove ${currency.code}? Shoppers using it will fall back to naira.`,
                                                            )
                                                        ) {
                                                            form.delete(
                                                                route(
                                                                    'admin.settings.currencies.destroy',
                                                                    currency.id,
                                                                ),
                                                                { preserveScroll: true },
                                                            );
                                                        }
                                                    }}
                                                    aria-label={`Remove ${currency.code}`}
                                                    className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {(creating || editing) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                        <div className="flex items-start justify-between">
                            <h2 className="text-lg font-extrabold text-gray-900">
                                {editing ? `Edit ${editing.code}` : 'Add a currency'}
                            </h2>
                            <button
                                type="button"
                                onClick={close}
                                aria-label="Close"
                                className="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        {editing?.isBase && (
                            <p className="mt-3 rounded-lg bg-gray-50 p-3 text-xs text-gray-500">
                                The naira is the base currency: every price is stored in it and every charge is
                                settled in it, so its rate stays at 1 and it cannot be hidden.
                            </p>
                        )}

                        <form onSubmit={submit} className="mt-4 space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <label className="block">
                                    <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                        ISO code
                                    </span>
                                    <Input
                                        value={form.data.code}
                                        onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                                        maxLength={3}
                                        placeholder="USD"
                                        disabled={editing?.isBase}
                                        required
                                    />
                                    <InputError message={form.errors.code} className="mt-1" />
                                </label>
                                <label className="block">
                                    <span className="mb-1.5 block text-xs font-bold text-gray-700">Symbol</span>
                                    <Input
                                        value={form.data.symbol}
                                        onChange={(e) => form.setData('symbol', e.target.value)}
                                        maxLength={8}
                                        placeholder="$"
                                        required
                                    />
                                    <InputError message={form.errors.symbol} className="mt-1" />
                                </label>
                            </div>

                            <label className="block">
                                <span className="mb-1.5 block text-xs font-bold text-gray-700">Name</span>
                                <Input
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="US Dollar"
                                    required
                                />
                                <InputError message={form.errors.name} className="mt-1" />
                            </label>

                            <div className="grid grid-cols-2 gap-4">
                                <label className="block">
                                    <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                        Units per ₦1
                                    </span>
                                    <Input
                                        type="number"
                                        step="0.0000000001"
                                        min="0"
                                        value={form.data.units_per_naira}
                                        onChange={(e) =>
                                            form.setData(
                                                'units_per_naira',
                                                e.target.value === '' ? '' : Number(e.target.value),
                                            )
                                        }
                                        disabled={editing?.isBase}
                                        required
                                    />
                                    <p className="mt-1 text-xs text-gray-400">
                                        At ₦1,540 to the dollar this is 0.00065.
                                    </p>
                                    <InputError message={form.errors.units_per_naira} className="mt-1" />
                                </label>
                                <label className="block">
                                    <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                        Decimal places
                                    </span>
                                    <Input
                                        type="number"
                                        min={0}
                                        max={4}
                                        value={form.data.decimals}
                                        onChange={(e) => form.setData('decimals', Number(e.target.value))}
                                        required
                                    />
                                    <p className="mt-1 text-xs text-gray-400">0 for ₦, XOF, ¥.</p>
                                    <InputError message={form.errors.decimals} className="mt-1" />
                                </label>
                            </div>

                            <label className="flex items-center gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_active}
                                    onChange={(e) => form.setData('is_active', e.target.checked)}
                                    disabled={editing?.isBase}
                                    className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                />
                                <span className="text-sm text-gray-700">Show this currency to shoppers</span>
                            </label>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={close}
                                    className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-500 transition hover:bg-gray-100"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                                >
                                    {editing ? 'Save changes' : 'Add currency'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
