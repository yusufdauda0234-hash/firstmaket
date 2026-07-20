import { cn } from '@/Utils/cn';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: string;
    /** Small uppercase eyebrow above the title. */
    eyebrow?: string;
    /** Back-link href; renders a round back button when set. */
    backHref?: string;
    backLabel?: string;
    /** Right-aligned actions (buttons / links). */
    actions?: ReactNode;
    className?: string;
}

/**
 * Consistent page heading for the portal pages: optional eyebrow + back link,
 * bold title, muted description, and a right-aligned actions slot.
 */
export default function PageHeader({
    title,
    description,
    eyebrow,
    backHref,
    backLabel = 'Back',
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div className={cn('mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between', className)}>
            <div className="min-w-0">
                {backHref && (
                    <Link
                        href={backHref}
                        className="mb-2 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 transition hover:gap-2.5 hover:text-brand-700"
                    >
                        <ArrowLeft className="h-4 w-4" /> {backLabel}
                    </Link>
                )}
                {eyebrow && (
                    <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-600">{eyebrow}</p>
                )}
                <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-gray-900">{title}</h1>
                {description && <p className="mt-1 max-w-2xl text-sm text-gray-500">{description}</p>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
