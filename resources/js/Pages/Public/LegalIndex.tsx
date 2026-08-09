import PublicLayout from '@/Layouts/PublicLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, ScrollText } from 'lucide-react';

interface Props {
    pages: { title: string; summary: string | null; url: string }[];
    [key: string]: unknown;
}

/** Index of every published page, so none is reachable only by knowing the URL. */
export default function LegalIndex() {
    const { pages } = usePage<Props>().props;

    return (
        <PublicLayout>
            <Head title="Legal & policies">
                <meta
                    name="description"
                    content="FirstMaket's terms of service, privacy policy and other published policies."
                />
            </Head>

            <div className="mx-auto w-full max-w-3xl px-4 py-8 sm:py-12">
                <div className="text-center">
                    <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                        <ScrollText className="h-7 w-7" />
                    </span>
                    <h1 className="mt-3 text-2xl font-extrabold tracking-tight text-gray-900 sm:text-3xl">
                        Legal &amp; policies
                    </h1>
                    <p className="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                        The terms you agree to when you use FirstMaket, and what we do with your information.
                    </p>
                </div>

                {pages.length === 0 ? (
                    <p className="mt-8 rounded-2xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500">
                        Nothing published yet.
                    </p>
                ) : (
                    <div className="mt-8 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                        {pages.map((page) => (
                            <Link
                                key={page.url}
                                href={page.url}
                                className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 transition last:border-0 hover:bg-slate-50"
                            >
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold text-gray-900">{page.title}</span>
                                    {page.summary !== null && (
                                        <span className="mt-0.5 block text-sm leading-relaxed text-gray-500">
                                            {page.summary}
                                        </span>
                                    )}
                                </span>
                                <ChevronRight className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
