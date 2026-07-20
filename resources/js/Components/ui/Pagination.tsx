import { Link } from '@inertiajs/react';

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export function Pagination({ links }: { links: PaginationLink[] }) {
    if (links.length <= 3) return null;

    return (
        <nav className="mt-4 flex flex-wrap items-center justify-center gap-1" aria-label="Pagination">
            {links.map((link, index) =>
                link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        className={
                            link.active
                                ? 'rounded-md bg-brand-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm'
                                : 'rounded-md px-3 py-1.5 text-sm text-gray-600 transition hover:bg-brand-50 hover:text-brand-700'
                        }
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span
                        key={index}
                        className="px-3 py-1.5 text-sm text-gray-400"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}
