import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    priceOutlierThresholdPercent: number;
    [key: string]: unknown;
}

export default function AiSettings() {
    const { priceOutlierThresholdPercent } = usePage<Props>().props;

    const form = useForm({ price_outlier_threshold_percent: String(priceOutlierThresholdPercent) });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('admin.settings.ai.update'), { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="AI settings" />

            <PageHeader
                eyebrow="Listing Review Assistant"
                title="AI settings"
                description="Every submitted listing is checked automatically and advisory flags show in the approval queue — the AI never approves or rejects a listing itself."
            />

            <Card className="max-w-xl">
                <form onSubmit={submit}>
                    <label htmlFor="threshold" className="flex items-center gap-2 text-sm font-bold text-gray-900">
                        <Sparkles className="h-4 w-4 text-violet-500" /> Price-outlier threshold
                    </label>
                    <p className="mt-1 text-sm text-gray-500">
                        Flag a listing when its price differs from its category's average approved price by more than
                        this percentage.
                    </p>
                    <div className="mt-3 flex items-center rounded-2xl border border-gray-200 px-4 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/15">
                        <input
                            id="threshold"
                            type="number"
                            min="5"
                            max="500"
                            step="1"
                            value={form.data.price_outlier_threshold_percent}
                            onChange={(e) => form.setData('price_outlier_threshold_percent', e.target.value)}
                            className="w-full border-0 bg-transparent px-2 py-3 text-xl font-extrabold text-gray-900 focus:outline-none focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
                        />
                        <span className="text-lg font-bold text-gray-400">%</span>
                    </div>
                    <InputError message={form.errors.price_outlier_threshold_percent} className="mt-1" />

                    <Button type="submit" disabled={form.processing} className="mt-4 active:scale-[0.98]">
                        {form.processing ? 'Saving…' : 'Save'}
                    </Button>
                </form>
            </Card>
        </AdminLayout>
    );
}
