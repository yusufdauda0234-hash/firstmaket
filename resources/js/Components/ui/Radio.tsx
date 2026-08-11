import { cn } from '@/Utils/cn';
import { InputHTMLAttributes, forwardRef } from 'react';

/**
 * A radio button.
 *
 * Exists because `Input` is a text field — a full-width box with padding, a
 * shadow and a `rounded-lg` edge. Passing `type="radio"` to it and patching
 * the size back with `h-4 w-4` leaves the padding and the radius behind, so
 * the control ends up looking like nothing else in the app. Choice controls
 * are a different shape of thing and get their own primitive.
 */
export const Radio = forwardRef<HTMLInputElement, Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>>(
    ({ className, ...props }, ref) => (
        <input
            ref={ref}
            type="radio"
            className={cn(
                'h-4 w-4 shrink-0 border-gray-300 text-brand-600 transition focus:ring-2 focus:ring-brand-500/30 disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    ),
);

Radio.displayName = 'Radio';
