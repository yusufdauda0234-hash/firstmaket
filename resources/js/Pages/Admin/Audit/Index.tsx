import { Badge } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination, PaginationLink } from '@/Components/ui/Pagination';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, ScrollText } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';

interface LogRow {
    id: number;
    action: string;
    domain: string;
    actor: string;
    subject: string;
    oldValues: Record<string, unknown> | null;
    newValues: Record<string, unknown> | null;
    ip: string | null;
    at: string | null;
}

interface Props {
    logs: { data: LogRow[]; links: PaginationLink[]; total: number };
    filters: { domain: string; action: string; actor: string; days: number };
    ranges: number[];
    domains: { value: string; label: string; count: number }[];
    actions: { value: string; label: string }[];
    [key: string]: unknown;
}

/**
 * The audit trail.
 *
 * A log is only useful if you can find the one row you need, so the filters
 * come from the data itself — the domains and actions offered are the ones
 * actually present in the window, which means a feature shipped last week
 * shows up here without anybody remembering to add it to a list.
 */
export default function AuditIndex() {
    const { logs, filters, ranges, domains, actions } = usePage<Props>().props;

    const [actor, setActor] = useState(filters.actor);
    const [expanded, setExpanded] = useState<number | null>(null);

    const apply = (next: Partial<Props['filters']>) => {
        router.get(
            route('admin.audit.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        if (actor === filters.actor) {
            return;
        }

        const timer = window.setTimeout(() => apply({ actor }), 350);

        return () => window.clearTimeout(timer);
    }, [actor]);

    return (
        <AdminLayout>
            <Head title="Audit trail" />
            <PageHeader
                eyebrow="Oversight"
                title="Audit trail"
                description="Every recorded change: who did it, to what, and when. Read-only — nothing on this page can alter the record."
            />

            <Card className="mb-4 p-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">Area</label>
                        <Select
                            value={filters.domain}
                            // Changing area clears the action: an action from
                            // the previous area would filter to zero rows and
                            // read as "nothing happened".
                            onChange={(event) => apply({ domain: event.target.value, action: '' })}
                        >
                            <option value="">All areas</option>
                            {domains.map((domain) => (
                                <option key={domain.value} value={domain.value}>
                                    {domain.label} ({domain.count})
                                </option>
                            ))}
                        </Select>
                    </div>

                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">Action</label>
                        <Select value={filters.action} onChange={(event) => apply({ action: event.target.value })}>
                            <option value="">All actions</option>
                            {actions.map((action) => (
                                <option key={action.value} value={action.value}>
                                    {action.label}
                                </option>
                            ))}
                        </Select>
                    </div>

                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">Who</label>
                        <Input
                            value={actor}
                            onChange={(event) => setActor(event.target.value)}
                            placeholder="Name or email"
                        />
                    </div>

                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">Period</label>
                        <Select value={filters.days} onChange={(event) => apply({ days: Number(event.target.value) })}>
                            {ranges.map((days) => (
                                <option key={days} value={days}>
                                    Last {days} days
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
            </Card>

            <Card className="overflow-hidden">
                {logs.data.length === 0 ? (
                    <div className="p-10 text-center">
                        <ScrollText className="mx-auto h-10 w-10 text-gray-300" />
                        <p className="mt-3 text-sm text-gray-600">Nothing recorded for these filters.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">When</th>
                                    <th className="px-4 py-3 font-semibold">Who</th>
                                    <th className="px-4 py-3 font-semibold">Action</th>
                                    <th className="px-4 py-3 font-semibold">Subject</th>
                                    <th className="px-4 py-3 font-semibold">IP</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {logs.data.map((log) => {
                                    const hasDetail = log.oldValues !== null || log.newValues !== null;
                                    const open = expanded === log.id;

                                    return (
                                        <Fragment key={log.id}>
                                            <tr className="hover:bg-gray-50/60">
                                                <td className="whitespace-nowrap px-4 py-3 text-gray-600 tabular-nums">
                                                    {log.at}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span
                                                        className={
                                                            log.actor === 'System'
                                                                ? 'text-gray-400'
                                                                : 'font-medium text-gray-900'
                                                        }
                                                    >
                                                        {log.actor}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge tone="neutral">{log.domain}</Badge>{' '}
                                                    <span className="text-gray-700">
                                                        {log.action.replace(/^[^.]+\./, '').replace(/_/g, ' ')}
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-gray-500">{log.subject}</td>
                                                <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-400">
                                                    {log.ip ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    {hasDetail && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setExpanded(open ? null : log.id)}
                                                            aria-expanded={open}
                                                            className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline"
                                                        >
                                                            {open ? (
                                                                <ChevronDown className="h-3.5 w-3.5" />
                                                            ) : (
                                                                <ChevronRight className="h-3.5 w-3.5" />
                                                            )}
                                                            Details
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                            {open && (
                                                <tr className="bg-gray-50">
                                                    <td colSpan={6} className="px-4 py-4">
                                                        <div className="grid gap-4 sm:grid-cols-2">
                                                            <ValueBlock label="Before" values={log.oldValues} />
                                                            <ValueBlock label="After" values={log.newValues} />
                                                        </div>
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Pagination links={logs.links} />
        </AdminLayout>
    );
}

function ValueBlock({ label, values }: { label: string; values: Record<string, unknown> | null }) {
    return (
        <div>
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">{label}</h3>
            {values === null || Object.keys(values).length === 0 ? (
                <p className="text-xs text-gray-400">Not recorded.</p>
            ) : (
                <dl className="space-y-1 text-xs">
                    {Object.entries(values).map(([key, value]) => (
                        <div key={key} className="flex gap-2">
                            <dt className="shrink-0 font-medium text-gray-600">{key.replace(/_/g, ' ')}:</dt>
                            <dd className="min-w-0 break-words font-mono text-gray-800">
                                {typeof value === 'object' ? JSON.stringify(value) : String(value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}
