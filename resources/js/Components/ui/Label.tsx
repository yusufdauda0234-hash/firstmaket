import { cn } from '@/Utils/cn';
import { LabelHTMLAttributes } from 'react';

export function Label({ className, ...props }: LabelHTMLAttributes<HTMLLabelElement>) {
    return (
        <label
            className={cn('mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300', className)}
            {...props}
        />
    );
}
