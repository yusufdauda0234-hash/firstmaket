import { cn } from '@/Utils/cn';
import { HTMLAttributes } from 'react';

export function InputError({ message, className, ...props }: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p className={cn('mt-1 text-sm text-red-600', className)} {...props}>
            {message}
        </p>
    );
}
