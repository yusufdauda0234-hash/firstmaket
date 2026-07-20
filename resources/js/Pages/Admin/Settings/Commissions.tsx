import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Percent } from 'lucide-react';
import { useState } from 'react';

interface CategoryRow {
    id: number;
    name: string;
    ratePercent: string | null;
}

interface Props {
    categories: CategoryRow[];
    defaultRatePercent: number;
    [key: string]: unknown;
}

export default function CommissionSettings() {
    const { categories, defaultRatePercent } = usePage<Props>().props;
    const form = useForm({ category_id: 0, rate_percent: '' });
    const [editing, setEditing] = useState<number | null>(null);

    const startEdit = (category: CategoryRow) => {
        setEditing(category.id);
        form.setData({
            category_id: category.id,
            rate_percent: category.ratePercent ?? String(defaultRatePercent),
        });
    };

    return (
        <AdminLayout>
            <Head title="Commission rates" />

            <PageHeader
                eyebrow="Vendor settlement"
                title="Commission rates"
                description={`Per-category commission deducted from the locked price on confirmed delivery. Categories without a rate use the default (${defaultRatePercent}%). Existing orders keep their snapshot.`}
            />

            <Card className="max-w-2xl p-0">
                <ul className="divide-y divide-gray-100">
                    {categories.map((category) => (
                        <li key={category.id} className="flex items-center gap-3 px-5 py-3.5">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                                <Percent className="h-4 w-4" />
                            </span>
                            <span className="min-w-0 flex-1 text-sm font-semibold text-gray-900">
                                {category.name}
                            </span>

                            {editing === category.id ? (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        form.post(route('admin.settings.commissions.update'), {
                                            preserveScroll: true,
                                            onSuccess: () => setEditing(null),
                                        });
                                    }}
                                    className="flex items-center gap-2"
                                >
                                    <div className="flex items-center rounded-full border border-gray-200 px-3 focus-within:border-brand-500">
                                        <input
                                            type="number"
                                            min="0"
                                            max="50"
                                            step="0.5"
                                            autoFocus
                                            value={form.data.rate_percent}
                                            onChange={(e) => form.setData('rate_percent', e.target.value)}
                                            className="w-16 border-0 bg-transparent py-1.5 text-right text-sm font-bold text-gray-900 focus:outline-none focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
                                        />
                                        <span className="text-sm text-gray-400">%</span>
                                    </div>
                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="rounded-full bg-brand-600 px-4 py-1.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                                    >
                                        Save
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setEditing(null)}
                                        className="text-sm text-gray-400 hover:text-gray-600"
                                    >
                                        Cancel
                                    </button>
                                    <InputError message={form.errors.rate_percent} />
                                </form>
                            ) : (
                                <>
                                    <span className="text-sm font-bold text-gray-900">
                                        {category.ratePercent !== null
                                            ? `${category.ratePercent}%`
                                            : `${defaultRatePercent}% (default)`}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => startEdit(category)}
                                        className="text-sm font-semibold text-brand-600 hover:underline"
                                    >
                                        Edit
                                    </button>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
        </AdminLayout>
    );
}
