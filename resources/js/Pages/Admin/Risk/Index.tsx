import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination } from '@/Components/ui/Pagination';
import { Select } from '@/Components/ui/Select';
import { Paginated } from '@/Types';
import { Head, router, usePage } from '@inertiajs/react';
import { ShieldAlert, ShieldCheck } from 'lucide-react';

interface Flag {
    uuid: string;
    rule: string;
    severity: string;
    summary: string;
    evidence: Record<string, unknown> | null;
    status: string;
    subject: string;
    subjectKind: string;
    raisedAt: string;
    reviewedBy: string | null;
    reviewNote: string | null;
    outcome: string | null;
}

interface Props {
    flags: Paginated<Flag>;
    filters: { status: string };
    thresholds: Record<string, number>;
    [key: string]: unknown;
}

const SEVERITY: Record<string, string> = {
    high: 'bg-red-100 text-red-800',
    medium: 'bg-amber-100 text-amber-800',
    low: 'bg-slate-100 text-slate-600',
};

/** Reads better than the raw rule key in a queue somebody scans all day. */
const RULE_LABEL: Record<string, string> = {
    failed_payments: 'Repeated failed payments',
    rapid_plan_switching: 'Rapid plan switching',
    vendor_rejection_spike: 'Vendor rejection spike',
    vendor_return_spike: 'Vendor return spike',
};

export default function AdminRisk() {
    const { flags, filters } = usePage<Props>().props;

    const review = (uuid: string, outcome: string, prompt_: string) => {
        const note = prompt(prompt_);

        // A cancelled prompt returns null; an empty note is allowed.
        if (note !== null) {
            router.post(route('admin.risk.review', uuid), { outcome, note }, { preserveScroll: true });
        }
    };

    return (
        <AdminLayout>
            <Head title="Risk flags" />
            <PageHeader
                eyebrow="Phase 2D"
                title="Risk flags"
                description="Patterns worth a look. Nothing here has suspended anyone — reviewing a flag records what you decided, it does not carry it out."
            />

            <div className="mb-4 max-w-xs">
                <Select
                    aria-label="Filter by status"
                    value={filters.status}
                    onChange={(event) =>
                        router.get(route('admin.risk.index'), { status: event.target.value }, {
                            preserveState: true,
                            replace: true,
                        })
                    }
                >
                    <option value="open">Open</option>
                    <option value="reviewed">Reviewed</option>
                    <option value="all">All</option>
                </Select>
            </div>

            {flags.data.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center">
                    <ShieldCheck className="mx-auto h-10 w-10 text-emerald-300" />
                    <p className="mt-3 text-sm font-semibold text-gray-700">Nothing flagged</p>
                    <p className="mt-1 text-sm text-gray-500">The overnight sweep found nothing to review.</p>
                </div>
            ) : (
                <div className="space-y-3">
                    {flags.data.map((flag) => (
                        <article
                            key={flag.uuid}
                            className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                <div className="min-w-0">
                                    <p className="flex flex-wrap items-center gap-2 text-sm font-bold text-gray-900">
                                        <ShieldAlert className="h-4 w-4 shrink-0 text-amber-500" />
                                        {RULE_LABEL[flag.rule] ?? flag.rule}
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${
                                                SEVERITY[flag.severity] ?? SEVERITY.low
                                            }`}
                                        >
                                            {flag.severity}
                                        </span>
                                    </p>
                                    <p className="mt-1 text-sm text-gray-600">{flag.summary}</p>
                                    <p className="mt-1 text-xs text-gray-400">
                                        {flag.subjectKind === 'customer' ? 'Customer' : 'Vendor'}:{' '}
                                        {flag.subject} · raised {flag.raisedAt}
                                    </p>
                                </div>

                                {flag.status === 'reviewed' && (
                                    <span className="shrink-0 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold text-emerald-800">
                                        {flag.outcome?.replace('_', ' ')}
                                    </span>
                                )}
                            </div>

                            {/* The numbers that tripped it — a reviewer should
                                see the evidence, not just the label. */}
                            {flag.evidence && (
                                <dl className="mt-3 flex flex-wrap gap-x-5 gap-y-1 rounded-xl bg-slate-50 px-3.5 py-2.5 text-xs">
                                    {Object.entries(flag.evidence).map(([key, value]) => (
                                        <span key={key} className="flex gap-1.5">
                                            <dt className="text-gray-400">{key.replace(/_/g, ' ')}</dt>
                                            <dd className="font-bold tabular-nums text-gray-700">
                                                {String(value)}
                                            </dd>
                                        </span>
                                    ))}
                                </dl>
                            )}

                            {flag.reviewNote && (
                                <p className="mt-2 text-xs text-gray-500">
                                    <span className="font-semibold text-gray-700">
                                        {flag.reviewedBy}:{' '}
                                    </span>
                                    {flag.reviewNote}
                                </p>
                            )}

                            {flag.status === 'open' && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            review(flag.uuid, 'no_action', 'Why is no action needed?')
                                        }
                                        className="rounded-full border border-gray-200 px-4 py-2 text-xs font-bold text-gray-600 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        No action needed
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => review(flag.uuid, 'watching', 'What are you watching for?')}
                                        className="rounded-full border border-gray-200 px-4 py-2 text-xs font-bold text-gray-600 transition hover:border-amber-300 hover:text-amber-700"
                                    >
                                        Keep watching
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            review(flag.uuid, 'actioned', 'What did you do about it?')
                                        }
                                        className="rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700"
                                    >
                                        I have actioned it
                                    </button>
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}

            <div className="mt-6">
                <Pagination links={flags.links} />
            </div>
        </AdminLayout>
    );
}
