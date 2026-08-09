import PublicLayout from '@/Layouts/PublicLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

type Block =
    | { type: 'paragraph'; text: string }
    | { type: 'bullets'; items: string[] }
    | { type: 'numbers'; items: string[] };

interface Section {
    heading: string | null;
    blocks: Block[];
}

interface Props {
    page: {
        title: string;
        summary: string | null;
        sections: Section[];
        effectiveAt: string | null;
        updatedAt: string | null;
        url: string;
    };
    [key: string]: unknown;
}

/** Slug used as the in-page anchor, so a section can be linked to directly. */
function anchor(heading: string): string {
    return heading
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
}

function longDate(value: string | null): string | null {
    if (value === null) return null;

    const parsed = new Date(`${value}T00:00:00`);

    return Number.isNaN(parsed.getTime())
        ? null
        : parsed.toLocaleDateString('en-NG', { day: 'numeric', month: 'long', year: 'numeric' });
}

/**
 * Renders whatever the admin has written on the Legal pages screen.
 *
 * Text only — no dangerouslySetInnerHTML anywhere. The blocks arrive already
 * split into paragraphs and lists by the server, so an admin account cannot
 * put markup on a page every visitor loads.
 */
export default function LegalPage() {
    const { page } = usePage<Props>().props;

    const effective = longDate(page.effectiveAt);
    const updated = longDate(page.updatedAt);

    // Only sections with a heading are worth listing; an unheaded preamble
    // has nothing to link to.
    const contents = page.sections.filter((section) => section.heading !== null);

    return (
        <PublicLayout>
            <Head title={page.title}>
                {page.summary !== null && <meta name="description" content={page.summary} />}
            </Head>

            <div className="mx-auto w-full max-w-3xl px-4 py-8 sm:py-12">
                <nav aria-label="Breadcrumb" className="flex items-center gap-1 text-xs text-gray-500">
                    <Link href="/" className="transition hover:text-brand-600">
                        Home
                    </Link>
                    <ChevronRight className="h-3 w-3" aria-hidden="true" />
                    <Link href={route('legal.index')} className="transition hover:text-brand-600">
                        Legal
                    </Link>
                </nav>

                <header className="mt-3 border-b border-gray-200 pb-6">
                    <h1 className="text-2xl font-extrabold tracking-tight text-gray-900 sm:text-3xl">
                        {page.title}
                    </h1>

                    {page.summary !== null && (
                        <p className="mt-2 text-sm leading-relaxed text-gray-600">{page.summary}</p>
                    )}

                    {/* Which version this is. On a policy somebody has agreed
                        to, that is not decoration. */}
                    {(effective ?? updated) !== null && (
                        <p className="mt-3 text-xs text-gray-500">
                            {effective !== null && <>Effective {effective}</>}
                            {effective !== null && updated !== null && <span className="px-1.5">·</span>}
                            {updated !== null && <>Last updated {updated}</>}
                        </p>
                    )}
                </header>

                {contents.length > 1 && (
                    <nav aria-label="On this page" className="mt-6 rounded-2xl border border-gray-200 bg-slate-50 p-4">
                        <p className="text-xs font-bold uppercase tracking-wider text-gray-400">On this page</p>
                        <ol className="mt-2 space-y-1.5 text-sm">
                            {contents.map((section, index) => (
                                <li key={`${section.heading}-${index}`}>
                                    <a
                                        href={`#${anchor(section.heading as string)}`}
                                        className="text-brand-700 underline-offset-2 transition hover:underline"
                                    >
                                        {index + 1}. {section.heading}
                                    </a>
                                </li>
                            ))}
                        </ol>
                    </nav>
                )}

                <div className="mt-8 space-y-8">
                    {page.sections.map((section, index) => (
                        <section
                            key={index}
                            id={section.heading !== null ? anchor(section.heading) : undefined}
                            className="scroll-mt-24"
                        >
                            {section.heading !== null && (
                                <h2 className="text-lg font-bold tracking-tight text-gray-900">
                                    {section.heading}
                                </h2>
                            )}

                            <div className="mt-2 space-y-3">
                                {section.blocks.map((block, blockIndex) => {
                                    if (block.type === 'paragraph') {
                                        return (
                                            <p
                                                key={blockIndex}
                                                className="text-sm leading-relaxed text-gray-700"
                                            >
                                                {block.text}
                                            </p>
                                        );
                                    }

                                    const ListTag = block.type === 'numbers' ? 'ol' : 'ul';

                                    return (
                                        <ListTag
                                            key={blockIndex}
                                            className={
                                                block.type === 'numbers'
                                                    ? 'list-decimal space-y-1.5 pl-5 text-sm leading-relaxed text-gray-700 marker:text-gray-400'
                                                    : 'list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-gray-700 marker:text-gray-400'
                                            }
                                        >
                                            {block.items.map((item, itemIndex) => (
                                                <li key={itemIndex}>{item}</li>
                                            ))}
                                        </ListTag>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>

                <footer className="mt-10 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p className="text-sm font-semibold text-gray-900">Questions about this page?</p>
                    <p className="mt-1 text-sm leading-relaxed text-gray-600">
                        Our support team can help. See the{' '}
                        <Link href={route('faq')} className="font-semibold text-brand-700 hover:underline">
                            help centre
                        </Link>{' '}
                        or contact us using the details in the footer.
                    </p>
                </footer>
            </div>
        </PublicLayout>
    );
}
