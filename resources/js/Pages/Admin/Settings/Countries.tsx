import { Card } from '@/Components/ui/Card';
import Modal from '@/Components/ui/Modal';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Globe, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface CountryRow {
    id: number;
    code: string;
    name: string;
    isActive: boolean;
    sortOrder: number;
}

interface AvailableCountry {
    code: string;
    name: string;
}

interface Props {
    countries: CountryRow[];
    [key: string]: unknown;
}

export default function CountriesSettings() {
    const { countries } = usePage<Props>().props;
    const [adding, setAdding] = useState(false);
    const [availableCountries, setAvailableCountries] = useState<AvailableCountry[]>([]);
    const [loadingCountries, setLoadingCountries] = useState(false);
    const form = useForm({ code: '', name: '' });

    // Fetch available countries from API
    useEffect(() => {
        if (adding) {
            setLoadingCountries(true);
            fetch('/api/v1/countries/list/all')
                .then(res => res.json())
                .then(data => setAvailableCountries(data.countries || []))
                .catch(() => setAvailableCountries([]))
                .finally(() => setLoadingCountries(false));
        }
    }, [adding]);

    const handleCountrySelect = (country: AvailableCountry) => {
        form.setData({ code: country.code, name: country.name });
    };

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
                <Modal
                    title="Add a new country"
                    open={adding}
                    onClose={() => {
                        setAdding(false);
                        form.reset();
                    }}
                    footer={
                        <>
                            <button
                                type="button"
                                onClick={() => {
                                    setAdding(false);
                                    form.reset();
                                }}
                                className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={handleAdd}
                                disabled={form.processing || !form.data.code || !form.data.name}
                                className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400"
                            >
                                {form.processing ? 'Adding…' : 'Add country'}
                            </button>
                        </>
                    }
                >
                    <div className="space-y-4">
                        <div>
                            <label className="mb-2 block text-sm font-bold text-gray-900">
                                {loadingCountries ? 'Loading countries...' : 'Select a country'}
                            </label>
                            <div className="max-h-96 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3">
                                {availableCountries.length === 0 ? (
                                    <p className="text-sm text-gray-500">No countries available</p>
                                ) : (
                                    availableCountries.map((country) => (
                                        <button
                                            key={country.code}
                                            type="button"
                                            onClick={() => handleCountrySelect(country)}
                                            className={`w-full rounded-lg px-3 py-2 text-left text-sm transition ${
                                                form.data.code === country.code
                                                    ? 'bg-brand-100 text-brand-700'
                                                    : 'bg-gray-50 text-gray-900 hover:bg-gray-100'
                                            }`}
                                        >
                                            <span className="font-semibold">{country.name}</span>
                                            <span className="ml-2 text-gray-500">({country.code})</span>
                                        </button>
                                    ))
                                )}
                            </div>
                        </div>

                        {form.data.code && (
                            <div className="rounded-lg bg-brand-50 p-3">
                                <p className="text-sm text-brand-900">
                                    <strong>Selected:</strong> {form.data.name} ({form.data.code})
                                </p>
                            </div>
                        )}

                        {form.errors.code && (
                            <InputError message={form.errors.code} />
                        )}
                    </div>
                </Modal>
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
