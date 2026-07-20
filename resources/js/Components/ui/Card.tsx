import { cn } from '@/Utils/cn';
import { HTMLAttributes } from 'react';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'rounded-2xl border border-gray-200 bg-white p-6 shadow-sm',
                className,
            )}
            {...props}
        />
    );
}
