import { cn } from '@/Utils/cn';
import { PropsWithChildren } from 'react';

type Tone = 'neutral' | 'success' | 'warning' | 'danger';

// FirstMarket ships a single light design everywhere, so badge tones are
// solid light-surface colors — no dark-mode variants that would wash out
// on a white card when the OS theme is dark.
const toneClasses: Record<Tone, string> = {
    neutral: 'bg-gray-100 text-gray-700 ring-1 ring-inset ring-gray-200',
    success: 'bg-green-100 text-green-800 ring-1 ring-inset ring-green-200',
    warning: 'bg-amber-100 text-amber-800 ring-1 ring-inset ring-amber-200',
    danger: 'bg-red-100 text-red-800 ring-1 ring-inset ring-red-200',
};

const lightToneClasses = toneClasses;

/** Maps a user/vendor/verification status string to a badge tone. */
export function statusTone(status: string): Tone {
    switch (status) {
        case 'approved':
        case 'verified':
        case 'passed':
        case 'active':
            return 'success';
        case 'pending':
        case 'pending_verification':
        case 'unverified':
            return 'warning';
        case 'rejected':
        case 'failed':
        case 'suspended':
        case 'banned':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function Badge({
    tone = 'neutral',
    light = false,
    className,
    children,
}: PropsWithChildren<{ tone?: Tone; light?: boolean; className?: string }>) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize',
                light ? lightToneClasses[tone] : toneClasses[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
