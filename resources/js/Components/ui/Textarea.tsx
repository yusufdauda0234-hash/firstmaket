import { cn } from '@/Utils/cn';
import { TextareaHTMLAttributes, forwardRef } from 'react';

/**
 * Multi-line text field.
 *
 * Deliberately the same border, radius, padding and focus ring as `Input` —
 * the two sit next to each other in most forms, and every place that styled a
 * textarea by hand drifted to a different radius or border shade.
 */
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(
    ({ className, ...props }, ref) => (
        <textarea
            ref={ref}
            className={cn(
                'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 disabled:cursor-not-allowed disabled:bg-gray-50',
                className,
            )}
            {...props}
        />
    ),
);

Textarea.displayName = 'Textarea';
