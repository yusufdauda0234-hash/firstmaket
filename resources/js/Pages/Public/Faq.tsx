import PublicLayout from '@/Layouts/PublicLayout';
import { cn } from '@/Utils/cn';
import { Head, usePage } from '@inertiajs/react';
import { ChevronDown, LifeBuoy, MessageCircle, Phone } from 'lucide-react';
import { useState } from 'react';

interface FaqRow {
    id: number;
    category: string;
    question: string;
    answer: string;
}

interface Props {
    faqs: Record<string, FaqRow[]>;
    whatsappNumber: string;
    hotlineNumber: string;
    [key: string]: unknown;
}

/** Public FAQ page (Sprint 7) — no login required, linked from the footer. */
export default function PublicFaq() {
    const { faqs, whatsappNumber, hotlineNumber } = usePage<Props>().props;
    const [open, setOpen] = useState<number | null>(null);

    const whatsappHref = `https://wa.me/${whatsappNumber.replace(/[^0-9]/g, '')}`;

    return (
        <PublicLayout>
            <Head title="Help & FAQ" />

            <div className="mx-auto w-full max-w-3xl px-4 py-8">
                <div className="text-center">
                    <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                        <LifeBuoy className="h-7 w-7" />
                    </span>
                    <h1 className="mt-3 text-2xl font-extrabold tracking-tight text-gray-900 sm:text-3xl">
                        How can we help?
                    </h1>
                    <p className="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                        Answers to the questions we hear most. Still stuck? Reach us on WhatsApp or the hotline —
                        or open a ticket from your account's Support Center.
                    </p>
                    <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                        <a
                            href={whatsappHref}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-700 active:scale-95"
                        >
                            <MessageCircle className="h-4 w-4" /> WhatsApp us
                        </a>
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700">
                            <Phone className="h-4 w-4 text-brand-600" /> {hotlineNumber}
                        </span>
                    </div>
                </div>

                {Object.entries(faqs).map(([category, rows]) => (
                    <div key={category} className="mt-8">
                        <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">{category}</p>
                        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                            {rows.map((faq) => (
                                <div key={faq.id} className="border-b border-gray-100 last:border-0">
                                    <button
                                        type="button"
                                        onClick={() => setOpen(open === faq.id ? null : faq.id)}
                                        className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left text-sm font-semibold text-gray-900 transition hover:bg-slate-50"
                                    >
                                        {faq.question}
                                        <ChevronDown
                                            className={cn(
                                                'h-4 w-4 shrink-0 text-gray-400 transition-transform',
                                                open === faq.id && 'rotate-180',
                                            )}
                                        />
                                    </button>
                                    {open === faq.id && (
                                        <p className="px-5 pb-4 text-sm leading-relaxed text-gray-600">{faq.answer}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </PublicLayout>
    );
}
