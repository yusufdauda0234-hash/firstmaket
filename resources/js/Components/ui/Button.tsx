import { cn } from '@/Utils/cn';
import { ButtonHTMLAttributes, forwardRef } from 'react';

type Variant = 'primary' | 'secondary' | 'ghost';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
}

const variantClasses: Record<Variant, string> = {
    primary:
        'bg-brand-600 text-white shadow-sm shadow-brand-600/25 hover:bg-brand-700 hover:shadow-md hover:shadow-brand-600/30 focus-visible:outline-brand-600',
    secondary:
        'bg-white text-gray-700 border border-gray-300 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 focus-visible:outline-gray-400',
    ghost: 'text-gray-700 hover:bg-gray-100 hover:text-gray-900',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant = 'primary', disabled, ...props }, ref) => (
        <button
            ref={ref}
            disabled={disabled}
            className={cn(
                'inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition-all duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.98] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                variantClasses[variant],
                className,
            )}
            {...props}
        />
    ),
);

Button.displayName = 'Button';
