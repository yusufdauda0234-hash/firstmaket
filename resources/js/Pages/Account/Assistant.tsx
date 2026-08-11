import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, MessageSquare, Send, Sparkles, Trash2, X } from 'lucide-react';
import { useEffect, useRef } from 'react';

interface Message {
    id: number;
    role: string;
    body: string;
    evidence: Record<string, unknown> | null;
    at: string | null;
}

interface Recommendation {
    uuid: string;
    action: string;
    title: string;
    body: string;
    payload: Record<string, unknown> | null;
    evidence: Record<string, unknown> | null;
    planUuid: string | null;
}

interface Props {
    conversations: { uuid: string; title: string | null; lastMessageAt: string | null }[];
    current: { uuid: string; title: string | null; messages: Message[] } | null;
    recommendations: Recommendation[];
    remainingQuestions: number;
}

const STARTERS = [
    'How are my plans going?',
    'Am I behind on anything?',
    'How much should I be paying?',
    'When will I finish?',
    'Is there something cheaper I could switch to?',
];

/** Only ever renders numbers the backend already worked out. */
function Evidence({ evidence }: { evidence: Record<string, unknown> | null }) {
    if (!evidence || Object.keys(evidence).length === 0) return null;

    const label = (key: string) => key.replace(/_/g, ' ').replace(/\bkobo\b/, '').trim();
    const value = (key: string, raw: unknown) =>
        key.endsWith('_kobo') && typeof raw === 'number' ? formatNairaFromKobo(raw) : String(raw);

    return (
        <ul className="mt-2 flex flex-wrap gap-1.5">
            {Object.entries(evidence).map(([key, raw]) => (
                <li key={key} className="rounded bg-white/70 px-2 py-0.5 text-[11px] text-gray-500 ring-1 ring-gray-200">
                    {label(key)}: <strong className="font-semibold text-gray-700">{value(key, raw)}</strong>
                </li>
            ))}
        </ul>
    );
}

export default function Assistant({ conversations = [], current, recommendations = [], remainingQuestions }: Props) {
    const form = useForm({ message: '', conversation: current?.uuid ?? '' });
    const endRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        endRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [current?.messages.length]);

    const send = (text?: string) => {
        const message = text ?? form.data.message;
        if (message.trim() === '') return;

        form.transform((data) => ({ ...data, message, conversation: current?.uuid ?? '' }));
        form.post(route('assistant.ask'), { onSuccess: () => form.reset('message') });
    };

    return (
        <AccountLayout title="Savings assistant">
            <Head title="Savings assistant" />

            <section className="rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-brand-900 p-6 text-white shadow-lg sm:p-8">
                <p className="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-brand-200">
                    <Sparkles className="h-3.5 w-3.5" /> Savings assistant
                </p>
                <h1 className="mt-2 text-3xl font-extrabold tracking-tight">Ask about your own saving.</h1>
                <p className="mt-2 max-w-2xl text-sm text-slate-300">
                    Every answer is worked out from your own payments and plans, and shows what it was based on. It
                    can suggest things — it can never do them. Nothing changes on your account unless you say so.
                </p>
            </section>

            <div className="mt-6 grid gap-4 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div className="flex min-h-[420px] flex-col rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div className="flex-1 space-y-3 overflow-y-auto p-5">
                        {!current || current.messages.length === 0 ? (
                            <div className="flex h-full flex-col items-center justify-center py-10 text-center">
                                <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500">
                                    <MessageSquare className="h-7 w-7" />
                                </span>
                                <p className="mt-4 text-sm font-medium text-gray-900">Nothing asked yet</p>
                                <p className="mt-1 max-w-sm text-sm text-gray-500">
                                    Try one of these to start.
                                </p>
                                <div className="mt-4 flex flex-wrap justify-center gap-2">
                                    {STARTERS.map((starter) => (
                                        <button
                                            key={starter}
                                            onClick={() => send(starter)}
                                            className="rounded-full bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 transition hover:bg-brand-100"
                                        >
                                            {starter}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ) : (
                            current.messages.map((message) => (
                                <div
                                    key={message.id}
                                    className={`flex ${message.role === 'customer' ? 'justify-end' : 'justify-start'}`}
                                >
                                    <div
                                        className={`max-w-[85%] rounded-2xl px-4 py-3 ${
                                            message.role === 'customer'
                                                ? 'bg-brand-600 text-white'
                                                : 'bg-gray-50 text-gray-800'
                                        }`}
                                    >
                                        <p className="whitespace-pre-line text-sm leading-relaxed">{message.body}</p>
                                        {message.role !== 'customer' && <Evidence evidence={message.evidence} />}
                                        {message.at && (
                                            <p className={`mt-1.5 text-[11px] ${message.role === 'customer' ? 'text-white/60' : 'text-gray-400'}`}>
                                                {message.at}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))
                        )}
                        <div ref={endRef} />
                    </div>

                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            send();
                        }}
                        className="flex gap-2 border-t border-gray-100 p-4"
                    >
                        <input
                            value={form.data.message}
                            onChange={(event) => form.setData('message', event.target.value)}
                            placeholder="Ask about your plans…"
                            maxLength={500}
                            className="min-h-11 flex-1 rounded-lg border border-gray-300 px-3 text-sm"
                        />
                        <button
                            disabled={form.processing || form.data.message.trim() === ''}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50"
                        >
                            <Send className="h-4 w-4" /> Ask
                        </button>
                    </form>
                    {form.errors.message && <p className="px-4 pb-3 text-xs text-red-600">{form.errors.message}</p>}
                    <p className="px-4 pb-3 text-[11px] text-gray-400">
                        {remainingQuestions} question{remainingQuestions === 1 ? '' : 's'} left today.
                    </p>
                </div>

                <div className="space-y-4">
                    {recommendations.length > 0 && (
                        <section className="rounded-2xl border border-brand-200 bg-brand-50/50 p-4">
                            <h2 className="text-sm font-bold text-brand-900">Suggestions</h2>
                            <p className="mt-0.5 text-[11px] text-brand-700">
                                Nothing happens until you choose.
                            </p>
                            <div className="mt-3 space-y-3">
                                {recommendations.map((recommendation) => (
                                    <div key={recommendation.uuid} className="rounded-xl bg-white p-3 shadow-sm">
                                        <p className="text-sm font-bold text-gray-900">{recommendation.title}</p>
                                        <p className="mt-1 text-xs leading-relaxed text-gray-600">{recommendation.body}</p>
                                        <Evidence evidence={recommendation.evidence} />

                                        {Array.isArray(recommendation.payload?.options) && (
                                            <ul className="mt-2 space-y-1">
                                                {(recommendation.payload!.options as { uuid: string; name: string; priceKobo: number }[])
                                                    .slice(0, 3)
                                                    .map((option) => (
                                                        <li key={option.uuid} className="truncate text-[11px] text-gray-500">
                                                            {option.name} — {formatNairaFromKobo(option.priceKobo)}
                                                        </li>
                                                    ))}
                                            </ul>
                                        )}

                                        <div className="mt-3 flex gap-2">
                                            <button
                                                onClick={() =>
                                                    router.post(
                                                        route('assistant.recommendations.confirm', recommendation.uuid),
                                                        { decision: 'accepted' },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="inline-flex flex-1 items-center justify-center gap-1 rounded-lg bg-brand-600 px-2 py-1.5 text-xs font-bold text-white"
                                            >
                                                <Check className="h-3.5 w-3.5" /> Yes, do that
                                            </button>
                                            <button
                                                onClick={() =>
                                                    router.post(
                                                        route('assistant.recommendations.confirm', recommendation.uuid),
                                                        { decision: 'declined' },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="inline-flex items-center justify-center gap-1 rounded-lg border border-gray-300 px-2 py-1.5 text-xs font-semibold text-gray-600"
                                            >
                                                <X className="h-3.5 w-3.5" /> No
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}

                    <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                        <h2 className="text-sm font-bold text-gray-900">Earlier conversations</h2>
                        <div className="mt-3 space-y-1">
                            {conversations.length === 0 && <p className="text-xs text-gray-400">Nothing yet.</p>}
                            {conversations.map((conversation) => (
                                <div
                                    key={conversation.uuid}
                                    className={`group flex items-center gap-2 rounded-lg px-2 py-1.5 ${
                                        current?.uuid === conversation.uuid ? 'bg-brand-50' : 'hover:bg-gray-50'
                                    }`}
                                >
                                    <Link
                                        href={route('assistant.index', { conversation: conversation.uuid })}
                                        className="min-w-0 flex-1"
                                    >
                                        <p className="truncate text-xs font-semibold text-gray-700">{conversation.title}</p>
                                        <p className="text-[11px] text-gray-400">{conversation.lastMessageAt}</p>
                                    </Link>
                                    <button
                                        onClick={() => {
                                            if (!confirm('Delete this conversation?')) return;
                                            router.delete(route('assistant.conversations.destroy', conversation.uuid));
                                        }}
                                        className="shrink-0 rounded p-1 text-gray-300 opacity-0 transition group-hover:opacity-100 hover:text-red-500"
                                        aria-label="Delete conversation"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    </section>

                    <p className="rounded-xl bg-gray-50 px-3 py-2.5 text-[11px] leading-relaxed text-gray-500">
                        This assistant reads only your own plans and payments. It never sees anyone else's, and it
                        cannot take a payment, move money, or change anything without you confirming it first.
                    </p>
                </div>
            </div>
        </AccountLayout>
    );
}
