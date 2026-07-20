import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Send } from 'lucide-react';
import { FormEventHandler } from 'react';

interface MessageRow {
    id: number;
    body: string;
    mine: boolean;
    senderName: string;
    at: string | null;
}

interface Props {
    ticket: {
        uuid: string;
        subject: string;
        status: string;
        channel: string;
        createdAt: string;
        messages: MessageRow[];
    };
    [key: string]: unknown;
}

const statusStyle: Record<string, string> = {
    open: 'bg-sky-50 text-sky-700',
    pending: 'bg-amber-50 text-amber-700',
    resolved: 'bg-emerald-50 text-emerald-700',
    closed: 'bg-gray-100 text-gray-500',
};

export default function TicketShow() {
    const { ticket } = usePage<Props>().props;
    const form = useForm({ message: '' });

    const closed = ticket.status === 'resolved' || ticket.status === 'closed';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('support.tickets.reply', ticket.uuid), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <AccountLayout title="Ticket">
            <Head title={`Ticket — ${ticket.subject}`} />

            <Link
                href={route('support.index')}
                className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                <ArrowLeft className="h-4 w-4" /> Support Center
            </Link>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-extrabold tracking-tight text-gray-900">{ticket.subject}</h1>
                    <p className="mt-0.5 text-xs text-gray-400">
                        Ticket {ticket.uuid.slice(0, 8).toUpperCase()} · opened {ticket.createdAt}
                    </p>
                </div>
                <span
                    className={cn(
                        'rounded-full px-3 py-1 text-xs font-bold uppercase',
                        statusStyle[ticket.status] ?? 'bg-gray-100 text-gray-500',
                    )}
                >
                    {ticket.status}
                </span>
            </div>

            {/* ── Thread ── */}
            <Card className="mt-4 space-y-4">
                {ticket.messages.map((message) => (
                    <div key={message.id} className={cn('flex', message.mine ? 'justify-end' : 'justify-start')}>
                        <div
                            className={cn(
                                'max-w-[85%] rounded-2xl px-4 py-3 sm:max-w-[70%]',
                                message.mine
                                    ? 'rounded-br-md bg-brand-600 text-white'
                                    : 'rounded-bl-md bg-slate-100 text-gray-800',
                            )}
                        >
                            <p className="whitespace-pre-wrap text-sm leading-relaxed">{message.body}</p>
                            <p
                                className={cn(
                                    'mt-1.5 text-[11px]',
                                    message.mine ? 'text-brand-100' : 'text-gray-400',
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
            {closed ? (
                <p className="mt-4 rounded-2xl border border-gray-200 bg-gray-50 px-5 py-4 text-center text-sm text-gray-500">
                    This ticket is {ticket.status}. Need more help?{' '}
                    <Link href={route('support.index')} className="font-semibold text-brand-600 hover:underline">
                        Open a new ticket
                    </Link>
                    .
                </p>
            ) : (
                <form onSubmit={submit} className="mt-4 flex items-start gap-2">
                    <div className="flex-1">
                        <textarea
                            rows={2}
                            placeholder="Write a reply…"
                            value={form.data.message}
                            onChange={(e) => form.setData('message', e.target.value)}
                            required
                            className="w-full rounded-2xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                        />
                        <InputError message={form.errors.message} className="mt-1" />
                    </div>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                    >
                        <Send className="h-4 w-4" /> Send
                    </button>
                </form>
            )}
        </AccountLayout>
    );
}
