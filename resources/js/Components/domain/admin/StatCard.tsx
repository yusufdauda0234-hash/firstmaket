import { cn } from '@/Utils/cn';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { ComponentType } from 'react';

export interface StatCardProps {
    label: string;
    value: number | string;
    icon: ComponentType<{ className?: string }>;
    /** Tailwind classes for the icon chip, e.g. "bg-amber-100 text-amber-600". */
    accent: string;
    /** When set, the card links there and shows a "View →" affordance. */
    href?: string;
    /** Small helper line under the value, e.g. "Arrives in Sprint 7". */
    hint?: string;
    /** Optional trend chip, e.g. { value: 12, direction: 'up' }. */
    trend?: { value: number; direction: 'up' | 'down' | 'neutral' };
    /** Left accent bar tone; pairs with the icon accent for a colored edge. */
    tone?: 'brand' | 'emerald' | 'amber' | 'red' | 'violet' | 'slate';
    /** Kept for API compatibility — every surface renders light now. */
    light?: boolean;
}

const toneBar: Record<NonNullable<StatCardProps['tone']>, string> = {
    brand: 'before:bg-brand-500',
    emerald: 'before:bg-emerald-500',
    amber: 'before:bg-amber-500',
    red: 'before:bg-red-500',
    violet: 'before:bg-violet-500',
    slate: 'before:bg-slate-400',
};

const trendColor = {
    up: 'text-emerald-600 bg-emerald-50',
    down: 'text-red-600 bg-red-50',
    neutral: 'text-gray-500 bg-gray-100',
} as const;

const trendArrow = { up: '↑', down: '↓', neutral: '→' } as const;

/**
 * Staff/vendor dashboard stat tile (2026 style): colored left accent bar,
 * icon chip, big tabular number, optional trend chip and link affordance.
 * Cards with an href lift on hover.
 */
export default function StatCard({
    label,
    value,
    icon: Icon,
    accent,
    href,
    hint,
    trend,
    tone = 'brand',
}: StatCardProps) {
    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <span
                    className={cn(
                        'flex h-11 w-11 items-center justify-center rounded-xl transition-transform duration-300 group-hover:scale-110',
                        accent,
                    )}
                >
                    <Icon className="h-5 w-5" />
                </span>
                {href ? (
                    <span className="flex h-7 w-7 items-center justify-center rounded-full text-gray-300 transition-all duration-200 group-hover:bg-brand-50 group-hover:text-brand-600">
                        <ArrowUpRight className="h-4 w-4" />
                    </span>
                ) : trend ? (
                    <span
                        className={cn(
                            'inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-bold tabular-nums',
                            trendColor[trend.direction],
                        )}
                    >
                        {trendArrow[trend.direction]} {Math.abs(trend.value)}%
                    </span>
                ) : null}
            </div>
            <p className="mt-4 text-3xl font-extrabold tracking-tight tabular-nums text-gray-900">{value}</p>
            <p className="mt-1 text-sm text-gray-500">{label}</p>
            {hint && <p className="mt-2 text-[11px] text-gray-400">{hint}</p>}
        </>
    );

    const cardClasses = cn(
        'relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5 shadow-sm',
        // Colored left accent bar via ::before.
        "before:absolute before:inset-y-0 before:left-0 before:w-1 before:content-['']",
        toneBar[tone],
    );

    if (href) {
        return (
            <Link
                href={href}
                className={cn(
                    'group block transition duration-200 hover:-translate-y-1 hover:border-brand-200 hover:shadow-xl hover:shadow-brand-600/10',
                    cardClasses,
                )}
            >
                {body}
            </Link>
        );
    }

    return <div className={cn('group', cardClasses)}>{body}</div>;
}
