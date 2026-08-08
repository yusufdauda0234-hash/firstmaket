import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, BookOpen, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Section {
    id: string;
    group: string;
    title: string;
    route: string | null;
    summary: string;
    points: string[];
}

interface Props {
    sections: Section[];
    [key: string]: unknown;
}

/**
 * The admin manual.
 *
 * Replaces the per-screen help panels: an explanation sitting above a table is
 * read once and then in the way forever, whereas one page can be read start to
 * finish on someone's first day and searched when they are stuck.
 *
 * Only sections for screens the reader can actually open are sent, so nobody is
 * taught a page they will hit a 403 on.
 */
export default function Guide() {
    const { sections } = usePage<Props>().props;
    const [query, setQuery] = useState('');

    const matches = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (term === '') {
            return sections;
        }

        return sections.filter((section) =>
            [section.title, section.summary, section.group, ...section.points]
                .join(' ')
                .toLowerCase()
                .includes(term),
        );
    }, [sections, query]);

    // Group headings only make sense over the sections actually showing.
    const groups = useMemo(() => {
        const byGroup = new Map<string, Section[]>();

        matches.forEach((section) => {
            byGroup.set(section.group, [...(byGroup.get(section.group) ?? []), section]);
        });

        return [...byGroup.entries()];
    }, [matches]);

    return (
        <AdminLayout>
            <Head title="How this workspace works" />

            <PageHeader
                eyebrow="Admin guide"
                title="How this workspace works"
                description="What each screen is for, and the things worth knowing before you use it."
            />

            <div className="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                {/* Contents rail — sticky so it stays put through a long read. */}
                <nav className="hidden lg:block">
                    <div className="sticky top-6 space-y-4">
                        {groups.map(([group, items]) => (
                            <div key={group}>
                                <p className="mb-1.5 text-[11px] font-bold uppercase tracking-wide text-gray-400">
                                    {group}
                                </p>
                                <ul className="space-y-0.5">
                                    {items.map((section) => (
                                        <li key={section.id}>
                                            <a
                                                href={`#${section.id}`}
                                                className="block rounded-lg px-2 py-1.5 text-sm text-gray-600 transition hover:bg-brand-50 hover:text-brand-700"
                                            >
                                                {section.title}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </nav>

                <div className="min-w-0">
                    <div className="relative mb-5">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search the guide — try “recovery codes” or “reject”"
                            className="pl-9"
                            aria-label="Search the guide"
                        />
                    </div>

                    {matches.length === 0 ? (
                        <Card className="py-14 text-center">
                            <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                                <BookOpen className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">
                                Nothing in the guide matches “{query}”
                            </p>
                            <p className="mt-1 text-sm text-gray-500">Try a different word.</p>
                        </Card>
                    ) : (
                        <div className="space-y-5">
                            {matches.map((section) => (
                                <Card key={section.id} id={section.id} className="scroll-mt-6">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-[11px] font-bold uppercase tracking-wide text-brand-600">
                                                {section.group}
                                            </p>
                                            <h2 className="mt-0.5 text-lg font-extrabold text-gray-900">
                                                {section.title}
                                            </h2>
                                            <p className="mt-1 text-sm text-gray-500">{section.summary}</p>
                                        </div>

                                        {section.route && (
                                            <Link
                                                href={route(section.route)}
                                                className="inline-flex shrink-0 items-center gap-1 rounded-full border border-gray-200 px-3 py-1.5 text-xs font-bold text-gray-700 transition hover:border-brand-300 hover:text-brand-700"
                                            >
                                                Open <ArrowUpRight className="h-3.5 w-3.5" />
                                            </Link>
                                        )}
                                    </div>

                                    <ul className="mt-4 space-y-2 border-t border-gray-100 pt-4">
                                        {section.points.map((point, i) => (
                                            <li key={i} className="flex gap-2.5 text-sm leading-relaxed text-gray-700">
                                                <span
                                                    aria-hidden="true"
                                                    className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-300"
                                                />
                                                <span>{point}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
