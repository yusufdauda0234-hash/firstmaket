import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import { Select } from '@/Components/ui/Select';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/Utils/cn';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Search, Send } from 'lucide-react';
import { FormEventHandler } from 'react';

interface MessageRow {
    id: number;
    body: string;
    fromCustomer: boolean;
    senderName: string;
    at: string | null;
}

interface Props {
    ticket: {
        uuid: string;
        subject: string;
        status: string;
        priority: string;
        channel: string;
        customer: { id: number; name: string; email: string | null; phone: string | null };
        assigneeName: string | null;
        createdAt: string;
        messages: MessageRow[];
    };
    [key: string]: unknown;
}

const STATUSES = ['open', 'pending', 'resolved', 'closed'];

export default function SupportAdminShow() {
    const { ticket } = usePage<Props>().props;
    const replyForm = useForm({ message: '' });
    const statusForm = useForm({ status: ticket.status });

    const sendReply: FormEventHandler = (e) => {
        e.preventDefault();
        replyForm.post(route('admin.support.reply', ticket.uuid), {
            preserveScroll: true,
            onSuccess: () => replyForm.reset(),
        });
    };

    return (
        <AdminLayout>
            <Head title={`Ticket — ${ticket.subject}`} />

            <Link
                href={route('admin.support.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Support queue
            </Link>

            <PageHeader
                eyebrow={`Ticket ${ticket.uuid.slice(0, 8).toUpperCase()} · ${ticket.channel} · ${ticket.createdAt}`}
                title={ticket.subject}
                description={`${ticket.customer.name}${ticket.assigneeName ? ` · assigned to ${ticket.assigneeName}` : ' · unassigned'}`}
                actions={
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            statusForm.post(route('admin.support.status', ticket.uuid), { preserveScroll: true });
                        }}
                        className="flex items-center gap-2"
                    >
                        <Select
                            value={statusForm.data.status}
                            onChange={(e) => statusForm.setData('status', e.target.value)}
                            className="rounded-full"
                        >
                            {STATUSES.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </Select>
                        <button
                            type="submit"
                            disabled={statusForm.processing || statusForm.data.status === ticket.status}
                            className="rounded-full bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-50"
                        >
                            Update
                        </button>
                    </form>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_300px]">
                <div>
                    {/* ── Thread ── */}
                    <Card className="space-y-4">
                        {ticket.messages.map((message) => (
                            <div
                                key={message.id}
                                className={cn('flex', message.fromCustomer ? 'justify-start' : 'justify-end')}
                            >
                                <div
                                    className={cn(
                                        'max-w-[85%] rounded-2xl px-4 py-3 sm:max-w-[75%]',
                                        message.fromCustomer
                                            ? 'rounded-bl-md bg-slate-100 text-gray-800'
                                            : 'rounded-br-md bg-brand-600 text-white',
                                    )}
                                >
                                    <p className="whitespace-pre-wrap text-sm leading-relaxed">{message.body}</p>
                                    <p
                                        className={cn(
                                            'mt-1.5 text-[11px]',
                                            message.fromCustomer ? 'text-gray-400' : 'text-brand-100',
                                        )}
                                    >
                                        {message.senderName}
                                        {message.at && ` · ${message.at}`}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </Card>

                    {/* ── Reply ── */}
                    <form onSubmit={sendReply} className="mt-4 flex items-start gap-2">
                        <div className="flex-1">
                            <textarea
                                rows={3}
                                placeholder="Reply to the customer… (they are notified by their preferred channels)"
                                value={replyForm.data.message}
                                onChange={(e) => replyForm.setData('message', e.target.value)}
                                required
                                className="w-full rounded-2xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                            />
                            <InputError message={replyForm.errors.message} className="mt-1" />
                        </div>
                        <button
                            type="submit"
                            disabled={replyForm.processing}
                            className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                        >
                            <Send className="h-4 w-4" /> Reply
                        </button>
                    </form>
                </div>

                {/* ── Customer context ── */}
                <Card className="self-start">
                    <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Customer</h2>
                    <p className="mt-2 text-sm font-bold text-gray-900">{ticket.customer.name}</p>
                    {ticket.customer.email && <p className="mt-0.5 text-sm text-gray-600">{ticket.customer.email}</p>}
                    {ticket.customer.phone && <p className="mt-0.5 text-sm text-gray-600">{ticket.customer.phone}</p>}
                    <Link
                        href={route('admin.support.lookup', { q: ticket.customer.email ?? ticket.customer.name, customer: ticket.customer.id })}
                        className="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline"
                    >
                        <Search className="h-4 w-4" /> Full order & plan context
                    </Link>
                </Card>
            </div>
        </AdminLayout>
    );
}
