import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Globe, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface CountryRow {
    id: number;
    code: string;
    name: string;
    isActive: boolean;
    sortOrder: number;
}

interface Props {
    countries: CountryRow[];
    [key: string]: unknown;
}

export default function CountriesSettings() {
    const { countries } = usePage<Props>().props;
    const [adding, setAdding] = useState(false);
    const form = useForm({ code: '', name: '' });

    const handleAdd = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.settings.countries.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const toggleActive = (country: CountryRow) => {
        router.post(route('admin.settings.countries.toggle', country.id), {}, {
            preserveScroll: true,
        });
    };

    const remove = (country: CountryRow) => {
        if (!confirm(`Remove ${country.name}? Customers won't be able to select it for delivery.`)) return;

        router.delete(route('admin.settings.countries.destroy', country.id), {
            preserveScroll: true,
        });
    };

    const moveUp = (country: CountryRow) => {
        router.put(route('admin.settings.countries.update', country.id), {
            sort_order: Math.max(0, country.sortOrder - 1),
        }, {
            preserveScroll: true,
        });
    };

    const moveDown = (country: CountryRow) => {
        router.put(route('admin.settings.countries.update', country.id), {
            sort_order: country.sortOrder + 1,
        }, {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout>
            <Head title="Countries" />

            <PageHeader
                eyebrow="Shipping & logistics"
                title="Countries"
                description="Which countries customers can choose from when placing an order. States and LGAs are managed separately per country."
                actions={
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700"
                    >
                        <Plus className="h-4 w-4" /> Add a country
                    </button>
                }
            />

            {adding && (
                <Card>
                    <h3 className="text-sm font-bold text-gray-900">Add a new country</h3>
                    <form onSubmit={handleAdd} className="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label htmlFor="code" className="mb-1.5 block text-xs font-bold text-gray-700">
                                Country code
                            </label>
                            <Input
                                id="code"
                                type="text"
                                maxLength={2}
                                placeholder="e.g. NG"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                            />
                            <InputError message={form.errors.code} className="mt-1" />
                        </div>
                        <div>
                            <label htmlFor="name" className="mb-1.5 block text-xs font-bold text-gray-700">
                                Country name
                            </label>
                            <Input
                                id="name"
                                type="text"
                                placeholder="e.g. Nigeria"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>
                        <div className="sm:col-span-2 flex gap-2 justify-end">
                            <button
                                type="button"
                                onClick={() => { setAdding(false); form.reset(); }}
                                className="rounded-full px-4 py-2 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700 disabled:opacity-60"
                            >
                                {form.processing ? 'Adding…' : 'Add country'}
                            </button>
                        </div>
                    </form>
                </Card>
            )}

            {countries.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Globe className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No countries yet</p>
                    <p className="mt-1 max-w-md text-sm text-gray-500">
                        Add at least one country so customers can place orders.
                    </p>
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="mt-4 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700"
                    >
                        + Add the first country
                    </button>
                </Card>
            ) : (
                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-5 py-3 font-semibold">Country</th>
                                    <th className="px-5 py-3 font-semibold">Code</th>
                                    <th className="px-5 py-3 font-semibold">Status</th>
                                    <th className="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {countries.map((country, index) => (
                                    <tr key={country.id} className="transition-colors hover:bg-gray-50/60">
                                        <td className="px-5 py-3 font-semibold text-gray-900">{country.name}</td>
                                        <td className="px-5 py-3 font-mono text-xs text-gray-500">{country.code}</td>
                                        <td className="px-5 py-3">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${
                                                    country.isActive
                                                        ? 'bg-emerald-50 text-emerald-700'
                                                        : 'bg-gray-100 text-gray-500'
                                                }`}
                                            >
                                                {country.isActive ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3 text-right">
                                            <span className="inline-flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleActive(country)}
                                                    className="rounded-lg px-2.5 py-1.5 text-xs font-bold transition"
                                                    title={country.isActive ? 'Deactivate' : 'Activate'}
                                                >
                                                    {country.isActive ? (
                                                        <span className="text-emerald-600 hover:bg-emerald-50">
                                                            Deactivate
                                                        </span>
                                                    ) : (
                                                        <span className="text-brand-600 hover:bg-brand-50">
                                                            Activate
                                                        </span>
                                                    )}
                                                </button>
                                                {index > 0 && (
                                                    <button
                                                        type="button"
                                                        onClick={() => moveUp(country)}
                                                        className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                                        title="Move up"
                                                    >
                                                        <ChevronUp className="h-4 w-4" />
                                                    </button>
                                                )}
                                                {index < countries.length - 1 && (
                                                    <button
                                                        type="button"
                                                        onClick={() => moveDown(country)}
                                                        className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                                        title="Move down"
                                                    >
                                                        <ChevronDown className="h-4 w-4" />
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => remove(country)}
                                                    className="rounded-lg p-1.5 text-gray-300 transition hover:bg-red-50 hover:text-red-600"
                                                    title="Delete"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
        </AdminLayout>
    );
}
