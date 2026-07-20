import { cn } from '@/Utils/cn';
import { Check, Eye, EyeOff, X } from 'lucide-react';
import { useState } from 'react';

// ── Shared password rules (mirror the server's Password::defaults) ──
export const passwordRules = [
    { label: 'At least 8 characters', test: (p: string) => p.length >= 8 },
    { label: 'A letter', test: (p: string) => /[A-Za-z]/.test(p) },
    { label: 'A number', test: (p: string) => /\d/.test(p) },
];

export function isPasswordValid(password: string): boolean {
    return passwordRules.every((rule) => rule.test(password));
}

export function passwordsMatch(password: string, confirmation: string): boolean {
    return password.length > 0 && password === confirmation;
}

/** 0–4 strength score used only for the meter (UX, not the validity gate). */
function strengthScore(password: string): number {
    if (!password) return 0;
    let score = 0;
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
    if (/\d/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    return Math.min(score, 4);
}

const strengthMeta = [
    { label: 'Too weak', bar: 'bg-red-400', text: 'text-red-500', fill: 1 },
    { label: 'Weak', bar: 'bg-orange-400', text: 'text-orange-500', fill: 2 },
    { label: 'Good', bar: 'bg-amber-400', text: 'text-amber-600', fill: 3 },
    { label: 'Strong', bar: 'bg-emerald-500', text: 'text-emerald-600', fill: 4 },
];

interface PasswordFieldsProps {
    password: string;
    setPassword: (v: string) => void;
    confirmation: string;
    setConfirmation: (v: string) => void;
    inputClasses: string;
    passwordPlaceholder?: string;
    confirmPlaceholder?: string;
}

/**
 * Password + confirm-password with live feedback (#7): a strength meter, a
 * requirements checklist that ticks off as you type, an inline match/mismatch
 * indicator on the confirm field, and a show/hide toggle. Validity for the
 * submit gate comes from the exported isPasswordValid / passwordsMatch.
 */
export default function PasswordFields({
    password,
    setPassword,
    confirmation,
    setConfirmation,
    inputClasses,
    passwordPlaceholder = 'Password',
    confirmPlaceholder = 'Confirm password',
}: PasswordFieldsProps) {
    const [show, setShow] = useState(false);
    const score = strengthScore(password);
    const meta = score > 0 ? strengthMeta[score - 1] : null;
    const confirmTouched = confirmation.length > 0;
    const matches = passwordsMatch(password, confirmation);

    return (
        <div className="space-y-3">
            {/* Password */}
            <div className="relative">
                <input
                    type={show ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder={passwordPlaceholder}
                    autoComplete="new-password"
                    required
                    className={cn(inputClasses, 'pr-11')}
                />
                <button
                    type="button"
                    onClick={() => setShow((s) => !s)}
                    aria-label={show ? 'Hide password' : 'Show password'}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 transition hover:text-gray-600"
                >
                    {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
            </div>

            {/* Strength meter */}
            {password.length > 0 && meta && (
                <div className="px-1">
                    <div className="flex gap-1">
                        {[0, 1, 2, 3].map((i) => (
                            <span
                                key={i}
                                className={cn(
                                    'h-1.5 flex-1 rounded-full transition-colors duration-300',
                                    i < meta.fill ? meta.bar : 'bg-gray-200',
                                )}
                            />
                        ))}
                    </div>
                    <p className={cn('mt-1 text-xs font-medium', meta.text)}>Password strength: {meta.label}</p>
                </div>
            )}

            {/* Requirements checklist */}
            <ul className="grid grid-cols-1 gap-1 px-1 sm:grid-cols-3">
                {passwordRules.map((rule) => {
                    const ok = rule.test(password);
                    return (
                        <li
                            key={rule.label}
                            className={cn(
                                'flex items-center gap-1.5 text-xs transition-colors',
                                ok ? 'text-emerald-600' : 'text-gray-400',
                            )}
                        >
                            <span
                                className={cn(
                                    'flex h-4 w-4 shrink-0 items-center justify-center rounded-full transition-colors',
                                    ok ? 'bg-emerald-100' : 'bg-gray-100',
                                )}
                            >
                                {ok ? <Check className="h-2.5 w-2.5" /> : <span className="h-1 w-1 rounded-full bg-gray-300" />}
                            </span>
                            {rule.label}
                        </li>
                    );
                })}
            </ul>

            {/* Confirm */}
            <div className="relative">
                <input
                    type={show ? 'text' : 'password'}
                    value={confirmation}
                    onChange={(e) => setConfirmation(e.target.value)}
                    placeholder={confirmPlaceholder}
                    autoComplete="new-password"
                    required
                    className={cn(
                        inputClasses,
                        'pr-11',
                        confirmTouched && (matches ? 'border-emerald-400 focus:border-emerald-500' : 'border-red-300 focus:border-red-400'),
                    )}
                />
                {confirmTouched && (
                    <span
                        className={cn(
                            'absolute right-3 top-1/2 -translate-y-1/2',
                            matches ? 'text-emerald-500' : 'text-red-400',
                        )}
                    >
                        {matches ? <Check className="h-4 w-4" /> : <X className="h-4 w-4" />}
                    </span>
                )}
            </div>
            {confirmTouched && !matches && (
                <p className="px-1 text-xs text-red-500">Passwords don't match yet.</p>
            )}
        </div>
    );
}
