import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CreditCard, PauseCircle, RotateCcw } from 'lucide-react';
import { FormEventHandler, ReactNode } from 'react';

interface Props {
    settings: {
        returnWindowDays: number;
        refundDaysMin: number;
        refundDaysMax: number;
        maxPauseDays: number;
        debitRetryAfterHours: number;
        debitMaxFailures: number;
    };
    [key: string]: unknown;
}

/**
 * The operational numbers, editable without a deploy.
 *
 * The returns window is the one to be careful with: the same value is printed
 * on every product page and enforced when a customer tries to open a return,
 * so changing it here changes the promise and the rule together.
 */
export default function OperationsSettings() {
    const { settings } = usePage<Props>().props;

    const form = useForm({
        return_window_days: String(settings.returnWindowDays),
        refund_days_min: String(settings.refundDaysMin),
        refund_days_max: String(settings.refundDaysMax),
        max_pause_days: String(settings.maxPauseDays),
        debit_retry_after_hours: String(settings.debitRetryAfterHours),
        debit_max_failures: String(settings.debitMaxFailures),
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.settings.operations.update'), { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="Operations settings" />
            <PageHeader
                title="Operations settings"
                description="Returns, plan pauses and automatic payments. Cases already open keep the terms they were opened under."
            />

            <form onSubmit={submit} className="space-y-6">
                <Card>
                    <SectionTitle icon={<RotateCcw className="h-4 w-4 text-brand-600" />}>
                        Returns and refunds
                    </SectionTitle>
                    <p className="mt-1 text-sm text-gray-500">
                        The return window is printed on every product page and enforced when a
                        customer opens a return — this one field drives both.
                    </p>

                    <div className="mt-4 grid gap-4 sm:grid-cols-3">
                        <Field
                            label="Return window"
                            suffix="days from delivery"
                            error={form.errors.return_window_days}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="90"
                                value={form.data.return_window_days}
                                onChange={(event) =>
                                    form.setData('return_window_days', event.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label="Refund arrives — from"
                            suffix="working days"
                            error={form.errors.refund_days_min}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="60"
                                value={form.data.refund_days_min}
                                onChange={(event) => form.setData('refund_days_min', event.target.value)}
                            />
                        </Field>

                        <Field
                            label="Refund arrives — to"
                            suffix="working days"
                            error={form.errors.refund_days_max}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="90"
                                value={form.data.refund_days_max}
                                onChange={(event) => form.setData('refund_days_max', event.target.value)}
                            />
                        </Field>
                    </div>
                </Card>

                <Card>
                    <SectionTitle icon={<PauseCircle className="h-4 w-4 text-brand-600" />}>
                        Pausing a plan
                    </SectionTitle>
                    <p className="mt-1 text-sm text-gray-500">
                        A plan freezes its price, so a pause cannot run forever. After this many days
                        the pause lifts by itself and the plan is chased normally again.
                    </p>

                    <div className="mt-4 max-w-xs">
                        <Field label="Longest pause" suffix="days" error={form.errors.max_pause_days}>
                            <Input
                                type="number"
                                min="1"
                                max="365"
                                value={form.data.max_pause_days}
                                onChange={(event) => form.setData('max_pause_days', event.target.value)}
                            />
                        </Field>
                    </div>
                </Card>

                <Card>
                    <SectionTitle icon={<CreditCard className="h-4 w-4 text-brand-600" />}>
                        Automatic payments
                    </SectionTitle>
                    <p className="mt-1 text-sm text-gray-500">
                        How hard to try a saved card before stopping and asking the customer for a new
                        one. Keep the retry generous — repeatedly hitting somebody's bank is how a
                        card gets blocked.
                    </p>

                    <div className="mt-4 grid max-w-lg gap-4 sm:grid-cols-2">
                        <Field
                            label="Wait before retrying"
                            suffix="hours"
                            error={form.errors.debit_retry_after_hours}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="168"
                                value={form.data.debit_retry_after_hours}
                                onChange={(event) =>
                                    form.setData('debit_retry_after_hours', event.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label="Attempts before stopping"
                            suffix="including the first"
                            error={form.errors.debit_max_failures}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="5"
                                value={form.data.debit_max_failures}
                                onChange={(event) => form.setData('debit_max_failures', event.target.value)}
                            />
                        </Field>
                    </div>
                </Card>

                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save operations settings'}
                </Button>
            </form>
        </AdminLayout>
    );
}

function SectionTitle({ icon, children }: { icon: ReactNode; children: ReactNode }) {
    return (
        <h2 className="flex items-center gap-2 text-sm font-bold text-gray-900">
            {icon}
            {children}
        </h2>
    );
}

function Field({
    label,
    suffix,
    error,
    children,
}: {
    label: string;
    suffix?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-bold text-gray-700">{label}</label>
            {children}
            {error ? (
                <InputError message={error} className="mt-1" />
            ) : (
                suffix && <p className="mt-1 text-xs text-gray-400">{suffix}</p>
            )}
        </div>
    );
}
