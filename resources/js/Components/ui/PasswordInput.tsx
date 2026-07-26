import { cn } from '@/Utils/cn';
import { Eye, EyeOff } from 'lucide-react';
import { InputHTMLAttributes, forwardRef, useState } from 'react';
import { Input } from './Input';

/**
 * Password field with a show/hide toggle. The toggle is type="button" so it
 * never submits the surrounding form.
 */
export const PasswordInput = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
    ({ className, ...props }, ref) => {
        const [visible, setVisible] = useState(false);

        return (
            <div className="relative">
                <Input
                    ref={ref}
                    type={visible ? 'text' : 'password'}
                    className={cn('pr-10', className)}
                    {...props}
                />
                <button
                    type="button"
                    onClick={() => setVisible((prev) => !prev)}
                    className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-gray-400 hover:text-gray-600"
                    aria-label={visible ? 'Hide password' : 'Show password'}
                >
                    {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
            </div>
        );
    },
);

PasswordInput.displayName = 'PasswordInput';
