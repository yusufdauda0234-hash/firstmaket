import { cn } from '@/Utils/cn';
import { ClipboardEvent, KeyboardEvent, useEffect, useRef } from 'react';

interface OtpInputProps {
    /** Controlled value — the digits entered so far. */
    value: string;
    onChange: (value: string) => void;
    /** Fired once when all `length` digits are entered (auto-submit hook). */
    onComplete?: (value: string) => void;
    length?: number;
    autoFocus?: boolean;
    disabled?: boolean;
    error?: boolean;
}

/**
 * Segmented one-time-code input (banking-app pattern): one box per digit with
 * auto-advance, backspace-to-previous, arrow navigation, full paste support,
 * and an `onComplete` hook that fires exactly once when the last digit lands —
 * so the caller can auto-submit without a separate button.
 */
export default function OtpInput({
    value,
    onChange,
    onComplete,
    length = 6,
    autoFocus = false,
    disabled = false,
    error = false,
}: OtpInputProps) {
    const refs = useRef<(HTMLInputElement | null)[]>([]);
    const completedRef = useRef(false);

    useEffect(() => {
        if (autoFocus) refs.current[0]?.focus();
    }, [autoFocus]);

    // Re-arm the once-only completion guard whenever the value is cleared
    // (e.g. after a wrong code) so the next full entry fires onComplete again.
    useEffect(() => {
        if (value.length < length) completedRef.current = false;
    }, [value, length]);

    const digits = Array.from({ length }, (_, i) => value[i] ?? '');

    const commit = (next: string) => {
        const clean = next.replace(/\D/g, '').slice(0, length);
        onChange(clean);
        if (clean.length === length && !completedRef.current) {
            completedRef.current = true;
            onComplete?.(clean);
        }
    };

    const handleChange = (index: number, raw: string) => {
        const typed = raw.replace(/\D/g, '');

        if (typed === '') {
            const arr = digits.slice();
            arr[index] = '';
            commit(arr.join(''));
            return;
        }

        // Multiple digits (e.g. autofill dropping the whole code into one box).
        if (typed.length > 1) {
            commit((value.slice(0, index) + typed).slice(0, length));
            refs.current[Math.min(index + typed.length, length - 1)]?.focus();
            return;
        }

        const arr = digits.slice();
        arr[index] = typed;
        commit(arr.join(''));
        refs.current[Math.min(index + 1, length - 1)]?.focus();
    };

    const handleKeyDown = (index: number, e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Backspace' && digits[index] === '' && index > 0) {
            refs.current[index - 1]?.focus();
        } else if (e.key === 'ArrowLeft' && index > 0) {
            e.preventDefault();
            refs.current[index - 1]?.focus();
        } else if (e.key === 'ArrowRight' && index < length - 1) {
            e.preventDefault();
            refs.current[index + 1]?.focus();
        }
    };

    const handlePaste = (e: ClipboardEvent<HTMLDivElement>) => {
        const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, length);
        if (!pasted) return;
        e.preventDefault();
        commit(pasted);
        refs.current[Math.min(pasted.length, length - 1)]?.focus();
    };

    return (
        <div className="flex justify-center gap-2 sm:gap-2.5" onPaste={handlePaste}>
            {digits.map((digit, index) => (
                <input
                    key={index}
                    ref={(el) => (refs.current[index] = el)}
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    autoComplete={index === 0 ? 'one-time-code' : 'off'}
                    maxLength={1}
                    value={digit}
                    disabled={disabled}
                    aria-label={`Digit ${index + 1}`}
                    onChange={(e) => handleChange(index, e.target.value)}
                    onKeyDown={(e) => handleKeyDown(index, e)}
                    onFocus={(e) => e.target.select()}
                    className={cn(
                        'h-12 w-11 rounded-xl border bg-white text-center text-lg font-bold text-slate-900 shadow-sm transition',
                        'focus:outline-none focus:ring-4',
                        error
                            ? 'border-red-400 focus:border-red-500 focus:ring-red-500/10'
                            : digit
                              ? 'border-brand-400 focus:border-brand-600 focus:ring-brand-600/10'
                              : 'border-gray-200 focus:border-brand-600 focus:ring-brand-600/10',
                        disabled && 'cursor-not-allowed opacity-60',
                    )}
                />
            ))}
        </div>
    );
}
