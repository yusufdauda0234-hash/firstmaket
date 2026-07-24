import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    Headphones,
    LifeBuoy,
    MessageCircle,
    MessageSquarePlus,
    Phone,
    Ticket as TicketIcon,
} from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface FaqRow {
    id: number;
    category: string;
    question: string;
    answer: string;
}

interface TicketRow {
    uuid: string;
    subject: string;
    status: string;
    channel: string;
    createdAt: string;
}

interface Props {
    faqs: Record<string, FaqRow[]>;
    tickets: TicketRow[];
    whatsappNumber: string;
    hotlineNumber: string;
    defaultPhone: string | null;
    ivrReasons: { value: string; label: string }[];
    [key: string]: unknown;
}

const ticketStatusStyle: Record<string, string> = {
    open: 'bg-sky-50 text-sky-700',
    pending: 'bg-amber-50 text-amber-700',
    resolved: 'bg-emerald-50 text-emerald-700',
    closed: 'bg-gray-100 text-gray-500',
};

export default function SupportIndex() {
    const { faqs, tickets, whatsappNumber, hotlineNumber, defaultPhone, ivrReasons } = usePage<Props>().props;

    const [openFaq, setOpenFaq] = useState<number | null>(null);
    const [showTicketForm, setShowTicketForm] = useState(false);
    const [showHotlineForm, setShowHotlineForm] = useState(false);

    const ticketForm = useForm({ subject: '', message: '' });
    const hotlineForm = useForm({ phone: defaultPhone ?? '', reason: 'general_inquiry' });

    const submitTicket: FormEventHandler = (e) => {
        e.preventDefault();
        ticketForm.post(route('support.tickets.store'));
    };

    const submitHotline: FormEventHandler = (e) => {
        e.preventDefault();
        hotlineForm.post(route('support.hotline.request'), {
            preserveScroll: true,
            onSuccess: () => setShowHotlineForm(false),
        });
    };

    const whatsappHref = `https://wa.me/${whatsappNumber.replace(/[^0-9]/g, '')}?text=${encodeURIComponent('Hello FirstMarketsupport!')}`;

    return (
        <AccountLayout title="Support">
            <Head title="Support Center" />

            <h1 className="flex items-center gap-2 text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">
                <LifeBuoy className="h-6 w-6 text-brand-600" /> Support Center
            </h1>
            <p className="mt-1 text-sm text-gray-500">
                Find answers fast, or reach us on WhatsApp, the hotline, or a support ticket.
            </p>

            {/* ── Contact channels ── */}
            <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <a
                    href={whatsappHref}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md"
                >
                    <span className="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                        <MessageCircle className="h-5 w-5" />
                    </span>
                    <span>
                        <span className="block text-sm font-bold text-gray-900">WhatsApp</span>
                        <span className="block text-xs text-gray-400">Chat with us now</span>
                    </span>
                </a>
                <button
                    type="button"
                    onClick={() => setShowHotlineForm((v) => !v)}
                    className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md"
                >
                    <span className="flex h-11 w-11 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                        <Headphones className="h-5 w-5" />
                    </span>
                    <span>
                        <span className="block text-sm font-bold text-gray-900">Request a call</span>
                        <span className="block text-xs text-gray-400">Hotline: {hotlineNumber}</span>
                    </span>
                </button>
                <button
                    type="button"
                    onClick={() => setShowTicketForm((v) => !v)}
                    className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md"
                >
                    <span className="flex h-11 w-11 items-center justify-center rounded-full bg-violet-50 text-violet-600">
                        <MessageSquarePlus className="h-5 w-5" />
                    </span>
                    <span>
                        <span className="block text-sm font-bold text-gray-900">Open a ticket</span>
                        <span className="block text-xs text-gray-400">We reply in the app + email</span>
                    </span>
                </button>
            </div>

            {/* ── Hotline request form ── */}
            {showHotlineForm && (
                <Card className="mt-4">
                    <h2 className="flex items-center gap-2 text-sm font-bold text-gray-900">
                        <Phone className="h-4 w-4 text-brand-600" /> Request a callback
                    </h2>
                    <form onSubmit={submitHotline} className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                        <div>
                            <input
                                type="tel"
                                placeholder="Phone number"
                                value={hotlineForm.data.phone}
                                onChange={(e) => hotlineForm.setData('phone', e.target.value)}
                                required
                                className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                            />
                            <InputError message={hotlineForm.errors.phone} className="mt-1" />
                        </div>
                        <div>
                            <select
                                value={hotlineForm.data.reason}
                                onChange={(e) => hotlineForm.setData('reason', e.target.value)}
                                className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                            >
                                {ivrReasons.map((reason) => (
                                    <option key={reason.value} value={reason.value}>
                                        {reason.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={hotlineForm.errors.reason} className="mt-1" />
                        </div>
                        <button
                            type="submit"
                            disabled={hotlineForm.processing}
                            className="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                        >
                            {hotlineForm.processing ? 'Sending…' : 'Request call'}
                        </button>
                    </form>
                </Card>
            )}

            {/* ── New ticket form ── */}
            {showTicketForm && (
                <Card className="mt-4">
                    <h2 className="flex items-center gap-2 text-sm font-bold text-gray-900">
                        <MessageSquarePlus className="h-4 w-4 text-brand-600" /> Open a support ticket
                    </h2>
                    <form onSubmit={submitTicket} className="mt-3 space-y-3">
                        <div>
                            <input
                                type="text"
                                placeholder="Subject (e.g. My delivery is late)"
                                value={ticketForm.data.subject}
                                onChange={(e) => ticketForm.setData('subject', e.target.value)}
                                required
                                className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                            />
                            <InputError message={ticketForm.errors.subject} className="mt-1" />
                        </div>
                        <div>
                            <textarea
                                rows={4}
                                placeholder="Tell us what happened — include order numbers if you have them."
                                value={ticketForm.data.message}
                                onChange={(e) => ticketForm.setData('message', e.target.value)}
                                required
                                className="w-full rounded-xl border-gray-200 text-sm focus:border-brand-500 focus:ring-brand-500/20"
                            />
                            <InputError message={ticketForm.errors.message} className="mt-1" />
                        </div>
                        <button
                            type="submit"
                            disabled={ticketForm.processing}
                            className="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                        >
                            {ticketForm.processing ? 'Opening…' : 'Submit ticket'}
                        </button>
                    </form>
                </Card>
            )}

            {/* ── My tickets ── */}
            {tickets.length > 0 && (
                <Card className="mt-4 p-0">
                    <h2 className="flex items-center gap-2 border-b border-gray-100 px-5 py-4 text-sm font-bold text-gray-900">
                        <TicketIcon className="h-4 w-4 text-brand-600" /> My tickets
                    </h2>
                    <ul className="divide-y divide-gray-100">
                        {tickets.map((ticket) => (
                            <li key={ticket.uuid}>
                                <Link
                                    href={route('support.tickets.show', ticket.uuid)}
                                    className="flex items-center gap-3 px-5 py-3.5 transition hover:bg-slate-50"
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-semibold text-gray-900">
                                            {ticket.subject}
                                        </span>
                                        <span className="block text-xs text-gray-400">{ticket.createdAt}</span>
                                    </span>
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase',
                                            ticketStatusStyle[ticket.status] ?? 'bg-gray-100 text-gray-500',
                                        )}
                                    >
                                        {ticket.status}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {/* ── FAQ ── */}
            <div className="mt-6">
                <h2 className="text-lg font-extrabold tracking-tight text-gray-900">Frequently asked questions</h2>
                {Object.entries(faqs).map(([category, rows]) => (
                    <div key={category} className="mt-4">
                        <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">{category}</p>
                        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                            {rows.map((faq) => (
                                <div key={faq.id} className="border-b border-gray-100 last:border-0">
                                    <button
                                        type="button"
                                        onClick={() => setOpenFaq(openFaq === faq.id ? null : faq.id)}
                                        className="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-left text-sm font-semibold text-gray-900 transition hover:bg-slate-50"
                                    >
                                        {faq.question}
                                        <ChevronDown
                                            className={cn(
                                                'h-4 w-4 shrink-0 text-gray-400 transition-transform',
                                                openFaq === faq.id && 'rotate-180',
                                            )}
                                        />
                                    </button>
                                    {openFaq === faq.id && (
                                        <p className="px-5 pb-4 text-sm leading-relaxed text-gray-600">{faq.answer}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AccountLayout>
    );
}
