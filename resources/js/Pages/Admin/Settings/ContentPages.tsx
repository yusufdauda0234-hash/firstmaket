import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { cn } from '@/Utils/cn';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowDown,
    ArrowUp,
    ExternalLink,
    Eye,
    EyeOff,
    Lock,
    Plus,
    ScrollText,
    Trash2,
} from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Section {
    heading: string;
    body: string;
}

interface ContentPage {
    uuid: string;
    slug: string;
    title: string;
    summary: string | null;
    sections: Section[];
    isPublished: boolean;
    showInFooter: boolean;
    sortOrder: number;
    isSystem: boolean;
    effectiveAt: string | null;
    updatedAt: string | null;
    url: string;
}

interface Props extends PageProps {
    pages: ContentPage[];
    requiredSlugs: string[];
}

const blank = {
    slug: '',
    title: '',
    summary: '',
    sections: [{ heading: '', body: '' }] as Section[],
    is_published: false,
    show_in_footer: false,
    sort_order: 0,
    effective_at: '',
};

/**
 * Where the wording of the terms, the privacy policy and the data-deletion
 * instructions actually lives.
 *
 * Editing happens on a full page rather than in a modal: a policy runs to a
 * dozen sections and several hundred words, and a scrolling box inside a
 * dialog is a bad place to write any of it.
 */
export default function ContentPages() {
    const { pages, requiredSlugs, mainSiteUrl } = usePage<Props>().props;
    const [editing, setEditing] = useState<ContentPage | null>(null);
    const [creating, setCreating] = useState(false);

    const form = useForm({ ...blank });

    // The system pages exist only to answer at a URL an outside service holds.
    // Missing or unpublished means that service's review fails, so it is worth
    // saying so on the screen rather than leaving it to be discovered.
    const missing = requiredSlugs.filter(
        (slug) => !pages.some((page) => page.slug === slug && page.isPublished),
    );

    function openCreate() {
        form.setData({ ...blank, sections: [{ heading: '', body: '' }] });
        form.clearErrors();
        setEditing(null);
        setCreating(true);
    }

    function openEdit(page: ContentPage) {
        form.setData({
            slug: page.slug,
            title: page.title,
            summary: page.summary ?? '',
            sections: page.sections.length > 0 ? page.sections : [{ heading: '', body: '' }],
            is_published: page.isPublished,
            show_in_footer: page.showInFooter,
            sort_order: page.sortOrder,
            effective_at: page.effectiveAt ?? '',
        });
        form.clearErrors();
        setCreating(false);
        setEditing(page);
    }

    function close() {
        setCreating(false);
        setEditing(null);
    }

    function setSections(next: Section[]) {
        form.setData('sections', next);
    }

    function updateSection(index: number, patch: Partial<Section>) {
        setSections(form.data.sections.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    }

    function addSection() {
        setSections([...form.data.sections, { heading: '', body: '' }]);
    }

    function removeSection(index: number) {
        const next = form.data.sections.filter((_, i) => i !== index);

        setSections(next.length > 0 ? next : [{ heading: '', body: '' }]);
    }

    function moveSection(index: number, direction: -1 | 1) {
        const target = index + direction;

        if (target < 0 || target >= form.data.sections.length) return;

        const next = [...form.data.sections];
        [next[index], next[target]] = [next[target], next[index]];
        setSections(next);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            form.put(route('admin.settings.pages.update', editing.uuid), {
                preserveScroll: true,
                onSuccess: close,
            });
        } else {
            form.post(route('admin.settings.pages.store'), {
                preserveScroll: true,
                onSuccess: close,
            });
        }
    };

    function remove(page: ContentPage) {
        const question = page.isSystem
            ? `Unpublish "${page.title}"? Visitors will get a "not found" page, and any sign-in provider holding this URL will fail its next check.`
            : `Delete "${page.title}"? This cannot be undone.`;

        if (!window.confirm(question)) return;

        router.delete(route('admin.settings.pages.destroy', page.uuid), { preserveScroll: true });
    }

    if (creating || editing) {
        const locked = editing?.isSystem ?? false;

        return (
            <AdminLayout>
                <Head title={editing ? `Edit ${editing.title}` : 'New page'} />

                <button
                    type="button"
                    onClick={close}
                    className="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-500 transition hover:text-gray-900"
                >
                    <ArrowLeft className="h-4 w-4" /> All pages
                </button>

                <form onSubmit={submit} className="mt-4 max-w-3xl space-y-6 pb-16">
                    <div>
                        <h1 className="text-2xl font-extrabold text-gray-900">
                            {editing ? editing.title : 'New page'}
                        </h1>
                        {locked && (
                            <p className="mt-2 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs leading-relaxed text-amber-900">
                                <Lock className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    This is a built-in page. Its web address is registered with Google and Meta,
                                    so the address cannot be changed and the page cannot be deleted — only
                                    unpublished. The wording is yours to edit freely.
                                </span>
                            </p>
                        )}
                    </div>

                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="block">
                                <span className="mb-1.5 block text-xs font-bold text-gray-700">Title</span>
                                <Input
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    placeholder="Privacy Policy"
                                    maxLength={120}
                                    required
                                />
                                <InputError message={form.errors.title} className="mt-1" />
                            </label>

                            <label className="block">
                                <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                    Web address
                                </span>
                                <Input
                                    value={form.data.slug}
                                    onChange={(e) =>
                                        form.setData('slug', e.target.value.toLowerCase().replace(/\s+/g, '-'))
                                    }
                                    placeholder="returns-policy"
                                    maxLength={80}
                                    disabled={locked}
                                    required
                                    className={locked ? 'bg-gray-50 text-gray-500' : undefined}
                                />
                                {/* The saved page already knows its own
                                    address; only a new one has to be guessed
                                    at. Stripping mainSiteUrl out of it went
                                    wrong the moment the two differed in case,
                                    printing the host twice. */}
                                <p className="mt-1 break-all text-xs text-gray-400">
                                    {editing
                                        ? editing.url
                                        : `${mainSiteUrl.toLowerCase()}/legal/${form.data.slug}`}
                                </p>
                                <InputError message={form.errors.slug} className="mt-1" />
                            </label>
                        </div>

                        <label className="mt-4 block">
                            <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                Summary <span className="font-normal text-gray-400">(optional)</span>
                            </span>
                            <Input
                                value={form.data.summary}
                                onChange={(e) => form.setData('summary', e.target.value)}
                                placeholder="What this page covers, in one line."
                                maxLength={300}
                            />
                            <p className="mt-1 text-xs text-gray-400">
                                Shown under the heading, and used as the description Google prints beneath the
                                link in search results.
                            </p>
                            <InputError message={form.errors.summary} className="mt-1" />
                        </label>
                    </div>

                    <div>
                        <div className="flex items-end justify-between gap-3">
                            <div>
                                <h2 className="text-sm font-bold text-gray-900">Sections</h2>
                                <p className="mt-0.5 text-xs text-gray-500">
                                    Leave a blank line between paragraphs. Start a line with{' '}
                                    <code className="rounded bg-gray-100 px-1 py-0.5 text-[11px]">-</code> for a
                                    bullet, or{' '}
                                    <code className="rounded bg-gray-100 px-1 py-0.5 text-[11px]">1.</code> for a
                                    numbered list.
                                </p>
                            </div>
                        </div>

                        <InputError message={form.errors.sections} className="mt-2" />

                        <div className="mt-3 space-y-3">
                            {form.data.sections.map((section, index) => (
                                <div
                                    key={index}
                                    className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[11px] font-bold text-gray-500">
                                            {index + 1}
                                        </span>
                                        <Input
                                            value={section.heading}
                                            onChange={(e) =>
                                                updateSection(index, { heading: e.target.value })
                                            }
                                            placeholder="Section heading"
                                            maxLength={150}
                                            className="font-semibold"
                                        />
                                        <div className="flex shrink-0 items-center">
                                            <button
                                                type="button"
                                                onClick={() => moveSection(index, -1)}
                                                disabled={index === 0}
                                                aria-label={`Move section ${index + 1} up`}
                                                className="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 disabled:hover:bg-transparent"
                                            >
                                                <ArrowUp className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => moveSection(index, 1)}
                                                disabled={index === form.data.sections.length - 1}
                                                aria-label={`Move section ${index + 1} down`}
                                                className="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 disabled:hover:bg-transparent"
                                            >
                                                <ArrowDown className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => removeSection(index)}
                                                aria-label={`Remove section ${index + 1}`}
                                                className="rounded-lg p-2 text-gray-400 transition hover:bg-rose-50 hover:text-rose-600"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>

                                    <textarea
                                        value={section.body}
                                        onChange={(e) => updateSection(index, { body: e.target.value })}
                                        rows={6}
                                        maxLength={20000}
                                        placeholder="What this section says."
                                        className="mt-2 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-relaxed text-gray-900 shadow-sm transition placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                    />
                                    <InputError
                                        message={
                                            form.errors[
                                                `sections.${index}.body` as keyof typeof form.errors
                                            ] as string | undefined
                                        }
                                        className="mt-1"
                                    />
                                </div>
                            ))}
                        </div>

                        <button
                            type="button"
                            onClick={addSection}
                            className="mt-3 inline-flex items-center gap-1.5 rounded-full border border-dashed border-gray-300 px-4 py-2.5 text-xs font-bold text-gray-600 transition hover:border-brand-400 hover:text-brand-700"
                        >
                            <Plus className="h-4 w-4" /> Add section
                        </button>
                    </div>

                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-bold text-gray-900">Publishing</h2>

                        <label className="mt-3 flex items-start gap-2.5">
                            <input
                                type="checkbox"
                                checked={form.data.is_published}
                                onChange={(e) => form.setData('is_published', e.target.checked)}
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                            />
                            <span className="text-sm text-gray-700">
                                Published
                                <span className="block text-xs text-gray-400">
                                    Unpublished pages return "not found" to visitors but stay editable here.
                                </span>
                            </span>
                        </label>

                        <label className="mt-3 flex items-start gap-2.5">
                            <input
                                type="checkbox"
                                checked={form.data.show_in_footer}
                                onChange={(e) => form.setData('show_in_footer', e.target.checked)}
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                            />
                            <span className="text-sm text-gray-700">
                                Link from the site footer
                                <span className="block text-xs text-gray-400">
                                    Appears in the Legal column on every storefront page.
                                </span>
                            </span>
                        </label>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <label className="block">
                                <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                    Effective date <span className="font-normal text-gray-400">(optional)</span>
                                </span>
                                <Input
                                    type="date"
                                    value={form.data.effective_at}
                                    onChange={(e) => form.setData('effective_at', e.target.value)}
                                />
                                <p className="mt-1 text-xs text-gray-400">
                                    Set this when the terms themselves change, not when you fix a typo — it is
                                    how a customer knows which version they agreed to.
                                </p>
                                <InputError message={form.errors.effective_at} className="mt-1" />
                            </label>

                            <label className="block">
                                <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                    Footer order
                                </span>
                                <Input
                                    type="number"
                                    min={0}
                                    max={999}
                                    value={form.data.sort_order}
                                    onChange={(e) => form.setData('sort_order', Number(e.target.value))}
                                />
                                <p className="mt-1 text-xs text-gray-400">Lower numbers come first.</p>
                                <InputError message={form.errors.sort_order} className="mt-1" />
                            </label>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-2">
                        {editing?.isPublished && (
                            <a
                                href={editing.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mr-auto inline-flex items-center gap-1.5 text-xs font-bold text-gray-500 transition hover:text-brand-700"
                            >
                                <ExternalLink className="h-4 w-4" /> View live page
                            </a>
                        )}
                        <button
                            type="button"
                            onClick={close}
                            className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-500 transition hover:bg-gray-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-full bg-brand-600 px-6 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                        >
                            {editing ? 'Save changes' : 'Create page'}
                        </button>
                    </div>
                </form>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout>
            <Head title="Legal pages" />

            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold text-gray-900">
                        <ScrollText className="h-6 w-6 text-brand-600" /> Legal pages
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm text-gray-500">
                        The terms customers agree to, what you do with their information, and how they ask for
                        their account to be deleted. Edited here rather than in code, so a policy can be
                        corrected the day it changes.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={openCreate}
                    className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700"
                >
                    <Plus className="h-4 w-4" /> New page
                </button>
            </div>

            {missing.length > 0 && (
                <div className="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <p className="text-sm font-bold text-amber-900">
                        {missing.length === 1
                            ? 'One required page is not published'
                            : `${missing.length} required pages are not published`}
                    </p>
                    <p className="mt-1 text-xs leading-relaxed text-amber-800">
                        Google's sign-in consent screen needs the terms and privacy policy, and Meta will not
                        approve Facebook login without a reachable data-deletion page. Until these answer,
                        "Continue with Google" and "Continue with Facebook" cannot be switched on for the
                        public. Missing: {missing.join(', ')}.
                    </p>
                </div>
            )}

            <div className="mt-5 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                {pages.length === 0 ? (
                    <p className="p-8 text-center text-sm text-gray-500">No pages yet.</p>
                ) : (
                    pages.map((page) => (
                        <div
                            key={page.uuid}
                            className="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-4 last:border-0"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-bold text-gray-900">{page.title}</span>
                                    <span
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold',
                                            page.isPublished
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-gray-100 text-gray-500',
                                        )}
                                    >
                                        {page.isPublished ? (
                                            <Eye className="h-3 w-3" />
                                        ) : (
                                            <EyeOff className="h-3 w-3" />
                                        )}
                                        {page.isPublished ? 'Live' : 'Draft'}
                                    </span>
                                    {page.isSystem && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-bold text-brand-700">
                                            <Lock className="h-3 w-3" /> Required
                                        </span>
                                    )}
                                </div>
                                <p className="mt-0.5 break-all text-xs text-gray-400">
                                    {page.url}
                                    {page.updatedAt !== null && (
                                        <span className="text-gray-300"> · updated {page.updatedAt}</span>
                                    )}
                                </p>
                            </div>

                            <div className="flex shrink-0 items-center gap-1">
                                {page.isPublished && (
                                    <a
                                        href={page.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        aria-label={`Open ${page.title}`}
                                        className="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                                    >
                                        <ExternalLink className="h-4 w-4" />
                                    </a>
                                )}
                                <button
                                    type="button"
                                    onClick={() => openEdit(page)}
                                    className="rounded-full border border-gray-200 px-4 py-2 text-xs font-bold text-gray-700 transition hover:bg-gray-50"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    onClick={() => remove(page)}
                                    aria-label={page.isSystem ? `Unpublish ${page.title}` : `Delete ${page.title}`}
                                    className="rounded-lg p-2 text-gray-400 transition hover:bg-rose-50 hover:text-rose-600"
                                >
                                    {page.isSystem ? <EyeOff className="h-4 w-4" /> : <Trash2 className="h-4 w-4" />}
                                </button>
                            </div>
                        </div>
                    ))
                )}
            </div>

            <p className="mt-4 text-xs leading-relaxed text-gray-400">
                An index of every published page is at{' '}
                <Link href="/legal" className="font-semibold text-gray-500 hover:underline">
                    /legal
                </Link>
                . Pages ticked "link from the site footer" also appear in the storefront footer.
            </p>
        </AdminLayout>
    );
}
