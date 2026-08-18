import { Card } from '@/Components/ui/Card';
import Modal from '@/Components/ui/Modal';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface LGARow {
    id: number;
    name: string;
    code: string | null;
    isActive: boolean;
    sortOrder: number;
}

interface Props {
    state: {
        id: number;
        name: string;
        code: string | null;
        countryId: number;
        countryName: string;
    };
    lgas: LGARow[];
    [key: string]: unknown;
}

export default function LocalGovernmentsSettings() {
    const { state, lgas } = usePage<Props>().props;
    const [adding, setAdding] = useState(false);
    const form = useForm({ name: '', code: '' });

    const handleAdd = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.settings.lgas.store', state.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const toggleActive = (lga: LGARow) => {
        router.post(route('admin.settings.lgas.toggle', [state.id, lga.id]), {}, {
            preserveScroll: true,
        });
    };

    const remove = (lga: LGARow) => {
        if (!confirm(`Delete ${lga.name}?`)) return;
        router.delete(route('admin.settings.lgas.destroy', [state.id, lga.id]), {
            preserveScroll: true,
        });
    };

    const moveUp = (lga: LGARow) => {
        router.put(route('admin.settings.lgas.update', [state.id, lga.id]), {
            sort_order: Math.max(0, lga.sortOrder - 1),
        }, {
            preserveScroll: true,
        });
    };

    const moveDown = (lga: LGARow) => {
        router.put(route('admin.settings.lgas.update', [state.id, lga.id]), {
            sort_order: lga.sortOrder + 1,
        }, {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout>
            <Head title={`LGAs - ${state.name}`} />

            <PageHeader
                eyebrow="Shipping & logistics"
                title={`LGAs - ${state.name}, ${state.countryName}`}
                description={`Manage Local Government Areas for ${state.name}.`}
                actions={
                    <Link
                        href={route('admin.settings.states.index', state.countryId)}
                        className="inline-flex items-center gap-1.5 rounded-full bg-gray-200 px-4 py-2 text-xs font-bold text-gray-700 transition hover:bg-gray-300"
                    >
                        ← Back to states
                    </Link>
                }
            />

            {adding && (
                <Modal
                    title={`Add an LGA to ${state.name}`}
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
                                disabled={form.processing || !form.data.name}
                                className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400"
                            >
                                {form.processing ? 'Adding…' : 'Add LGA'}
                            </button>
                        </>
                    }
                >
                    <div className="space-y-4">
                        <div>
                            <label htmlFor="name" className="mb-2 block text-sm font-bold text-gray-900">
                                LGA name
                            </label>
                            <input
                                id="name"
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Eti-Osa"
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                            />
                            {form.errors.name && <InputError message={form.errors.name} />}
                        </div>

                        <div>
                            <label htmlFor="code" className="mb-2 block text-sm font-bold text-gray-900">
                                LGA code (optional)
                            </label>
                            <input
                                id="code"
                                type="text"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder="e.g. EO"
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                            />
                            {form.errors.code && <InputError message={form.errors.code} />}
                        </div>
                    </div>
                </Modal>
            )}

            {lgas.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                        <Plus className="h-7 w-7" />
                    </span>
                    <p className="mt-4 text-sm font-medium text-gray-900">No LGAs yet</p>
                    <p className="mt-1 max-w-md text-sm text-gray-500">
                        Add Local Government Areas for {state.name}.
                    </p>
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="mt-4 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700"
                    >
                        + Add the first LGA
                    </button>
                </Card>
            ) : (
                <Card className="overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-5 py-3 font-semibold">LGA</th>
                                    <th className="px-5 py-3 font-semibold">Code</th>
                                    <th className="px-5 py-3 font-semibold">Status</th>
                                    <th className="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {lgas.map((lga, index) => (
                                    <tr key={lga.id} className="transition-colors hover:bg-gray-50/60">
                                        <td className="px-5 py-3 font-semibold text-gray-900">{lga.name}</td>
                                        <td className="px-5 py-3 font-mono text-xs text-gray-500">{lga.code || '-'}</td>
                                        <td className="px-5 py-3">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${
                                                    lga.isActive
                                                        ? 'bg-emerald-50 text-emerald-700'
                                                        : 'bg-gray-100 text-gray-500'
                                                }`}
                                            >
                                                {lga.isActive ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3 text-right">
                                            <span className="inline-flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleActive(lga)}
                                                    className="rounded-lg px-2.5 py-1.5 text-xs font-bold transition"
                                                    title={lga.isActive ? 'Deactivate' : 'Activate'}
                                                >
                                                    {lga.isActive ? (
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
                                                        onClick={() => moveUp(lga)}
                                                        className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                                        title="Move up"
                                                    >
                                                        <ChevronUp className="h-4 w-4" />
                                                    </button>
                                                )}
                                                {index < lgas.length - 1 && (
                                                    <button
                                                        type="button"
                                                        onClick={() => moveDown(lga)}
                                                        className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                                                        title="Move down"
                                                    >
                                                        <ChevronDown className="h-4 w-4" />
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => remove(lga)}
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

            <div className="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    onClick={() => setAdding(true)}
                    className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700"
                >
                    <Plus className="h-4 w-4" /> Add an LGA
                </button>
            </div>
        </AdminLayout>
    );
}
