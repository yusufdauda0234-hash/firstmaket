import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Select } from '@/Components/ui/Select';
import { Textarea } from '@/Components/ui/Textarea';
import AccountLayout from '@/Layouts/AccountLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { MessageSquareWarning } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    categories: { value: string; label: string }[];
    orders: { uuid: string; label: string }[];
    complaints: {
        uuid: string;
        subject: string;
        category: string | null;
        status: string;
        openedAt: string;
    }[];
    [key: string]: unknown;
}

const STATUS_TONE: Record<string, string> = {
    open: 'bg-amber-100 text-amber-800',
    pending: 'bg-brand-100 text-brand-800',
    resolved: 'bg-emerald-100 text-emerald-800',
    closed: 'bg-gray-100 text-gray-600',
};

/**
 * The Complaint Centre.
 *
 * Note what it does not ask for: a priority. The category decides urgency,
 * because everybody marks their own problem urgent and a self-rated priority
 * is worth nothing to the team triaging the queue.
 */
export default function ComplaintCreate() {
    const { categories, orders, complaints } = usePage<Props>().props;

    const form = useForm({
        category: categories[0]?.value ?? 'other',
        subject: '',
        message: '',
        order_uuid: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('support.complaints.store'));
    };

    return (
        <AccountLayout title="Complaint Centre">
            <Head title="Make a complaint" />

            <div className="mb-4">
                <h1 className="flex items-center gap-2 text-xl font-extrabold tracking-tight text-gray-900">
                    <MessageSquareWarning className="h-5 w-5 shrink-0 text-brand-600" />
                    Something went wrong?
                </h1>
                <p className="mt-1 max-w-prose text-sm leading-relaxed text-gray-500">
                    Tell us what happened and we will look into it. If it is about money or an item
                    that never arrived, it goes to the front of the queue automatically.
                </p>
            </div>

            <form
                onSubmit={submit}
                className="space-y-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
            >
                <div>
                    <label htmlFor="category" className="mb-1.5 block text-xs font-bold text-gray-700">
                        What is it about?
                    </label>
                    <Select
                        id="category"
                        value={form.data.category}
                        onChange={(event) => form.setData('category', event.target.value)}
                    >
                        {categories.map((category) => (
                            <option key={category.value} value={category.value}>
                                {category.label}
                            </option>
                        ))}
                    </Select>
                    <InputError message={form.errors.category} className="mt-1" />
                </div>

                {orders.length > 0 && (
                    <div>
                        <label htmlFor="order" className="mb-1.5 flex items-baseline gap-2 text-xs font-bold text-gray-700">
                            Which order? <span className="font-normal text-gray-400">Optional</span>
                        </label>
                        <Select
                            id="order"
                            value={form.data.order_uuid}
                            onChange={(event) => form.setData('order_uuid', event.target.value)}
                        >
                            <option value="">Not about a specific order</option>
                            {orders.map((order) => (
                                <option key={order.uuid} value={order.uuid}>
                                    {order.label}
                                </option>
                            ))}
                        </Select>
                        <InputError message={form.errors.order_uuid} className="mt-1" />
                        <p className="mt-1 text-xs text-gray-400">
                            Attaching the order saves us asking, and gets you an answer faster.
                        </p>
                    </div>
                )}

                <div>
                    <label htmlFor="subject" className="mb-1.5 block text-xs font-bold text-gray-700">
                        In one line
                    </label>
                    <Input
                        id="subject"
                        value={form.data.subject}
                        onChange={(event) => form.setData('subject', event.target.value)}
                        placeholder="e.g. Courier left without delivering"
                    />
                    <InputError message={form.errors.subject} className="mt-1" />
                </div>

                <div>
                    <label htmlFor="message" className="mb-1.5 block text-xs font-bold text-gray-700">
                        What happened?
                    </label>
                    <Textarea
                        id="message"
                        rows={6}
                        value={form.data.message}
                        onChange={(event) => form.setData('message', event.target.value)}
                        placeholder="Dates, names and amounts help us sort it out faster."
                    />
                    <InputError message={form.errors.message} className="mt-1" />
                </div>

                <button
                    type="submit"
                    disabled={form.processing}
                    className="rounded-full bg-brand-600 px-6 py-3 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                >
                    {form.processing ? 'Sending…' : 'Send complaint'}
                </button>
            </form>

            {complaints.length > 0 && (
                <section className="mt-6">
                    <h2 className="text-sm font-bold text-gray-900">Complaints you have made</h2>
                    <ul className="mt-3 space-y-2">
                        {complaints.map((complaint) => (
                            <li key={complaint.uuid}>
                                <Link
                                    href={route('support.tickets.show', complaint.uuid)}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-brand-200"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-bold text-gray-900">
                                            {complaint.subject}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-gray-500">
                                            {complaint.category} · {complaint.openedAt}
                                        </span>
                                    </span>
                                    <span
                                        className={`shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-bold capitalize ${
                                            STATUS_TONE[complaint.status] ?? STATUS_TONE.closed
                                        }`}
                                    >
                                        {complaint.status}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AccountLayout>
    );
}
