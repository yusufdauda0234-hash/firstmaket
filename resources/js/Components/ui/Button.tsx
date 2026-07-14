import { cn } from '@/Utils/cn';
import { ButtonHTMLAttributes, forwardRef } from 'react';

type Variant = 'primary' | 'secondary' | 'ghost';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
}

const variantClasses: Record<Variant, string> = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:outline-brand-600',
    secondary:
        'bg-white text-gray-900 border border-gray-300 hover:bg-gray-50 focus-visible:outline-gray-400 dark:bg-gray-900 dark:text-gray-100 dark:border-gray-700',
    ghost: 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant = 'primary', disabled, ...props }, ref) => (
        <button
            ref={ref}
            disabled={disabled}
            className={cn(
                'inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                variantClasses[variant],
                className,
            )}
            {...props}
        />
    ),
);

Button.displayName = 'Button';
