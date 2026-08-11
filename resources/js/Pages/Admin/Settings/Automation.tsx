import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Field {
    key: string;
    name: string;
    label: string;
    help: string;
    min: number;
    max: number;
    value: number;
    default: number;
}

interface Props {
    groups: { title: string; fields: Field[] }[];
    [key: string]: unknown;
}

/**
 * Every threshold behind the automated parts of the system, in one place.
 *
 * The form is generated from the same schema the server validates against, so
 * a threshold cannot exist in the code without appearing here — which is how
 * the previous set drifted into being "configurable" with nowhere to configure
 * them.
 */
export default function AutomationSettings() {
    const { groups } = usePage<Props>().props;

    const initial: Record<string, string> = {};
    groups.forEach((group) =>
        group.fields.forEach((field) => {
            initial[field.name] = String(field.value);
        }),
    );

    const form = useForm(initial);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.settings.automation.update'), { preserveScroll: true });
    };

    const resetGroup = (fields: Field[]) =>
        fields.forEach((field) => form.setData(field.name, String(field.default)));

    return (
        <AdminLayout>
            <Head title="Automation & rules" />
            <PageHeader
                title="Automation & rules"
                description="The thresholds the scheduled jobs, vendor ratings, risk flags and suggestions run on. Changes apply from the next run — nothing already decided is revisited."
            />

            <form onSubmit={submit} className="space-y-6">
                {groups.map((group) => (
                    <Card key={group.title}>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-sm font-bold text-gray-900">{group.title}</h2>
                            <button
                                type="button"
                                onClick={() => resetGroup(group.fields)}
                                className="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
                            >
                                <RotateCcw className="h-3.5 w-3.5" /> Reset to defaults
                            </button>
                        </div>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {group.fields.map((field) => (
                                <div key={field.key}>
                                    <label
                                        htmlFor={field.name}
                                        className="mb-1.5 block text-xs font-bold text-gray-700"
                                    >
                                        {field.label}
                                    </label>
                                    <Input
                                        id={field.name}
                                        type="number"
                                        min={field.min}
                                        max={field.max}
                                        value={form.data[field.name] ?? ''}
                                        onChange={(event) => form.setData(field.name, event.target.value)}
                                    />
                                    {form.errors[field.name] ? (
                                        <InputError message={form.errors[field.name]} className="mt-1" />
                                    ) : (
                                        <p className="mt-1 text-xs leading-snug text-gray-400">
                                            {field.help}
                                            {String(field.value) !== String(field.default) && (
                                                <span className="ml-1 text-gray-500">
                                                    (default {field.default})
                                                </span>
                                            )}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </Card>
                ))}

                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save automation settings'}
                </Button>
            </form>
        </AdminLayout>
    );
}
