import PasswordFields, { isPasswordValid, passwordsMatch } from '@/Components/domain/auth/PasswordFields';
import OtpInput from '@/Components/ui/OtpInput';
import { firstError, postJson } from '@/Utils/http';
import { router } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

type Step = 'entry' | 'password' | 'login-code' | 'register-code' | 'register-details' | 'reset';

interface IdentifyResponse {
    exists: boolean;
    channel: 'sms' | 'email';
    identifier: string;
    masked: string;
}

/**
 * The combined sign-in/register flow used by both the home page modal and
 * the standalone /login and /register pages (AliExpress pattern): one
 * email-or-phone field decides whether the visitor signs in or registers,
 * and OTP codes travel through the channel matching the identifier.
 */
export default function AuthPanel({ initialStep = 'entry' }: { initialStep?: Step }) {
    const [step, setStep] = useState<Step>(initialStep);
    const [identifier, setIdentifier] = useState('');
    const [masked, setMasked] = useState('');
    const [channel, setChannel] = useState<'sms' | 'email'>('email');
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [name, setName] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [resendIn, setResendIn] = useState(0);

    useEffect(() => {
        if (resendIn <= 0) return;
        const t = setTimeout(() => setResendIn((s) => s - 1), 1000);
        return () => clearTimeout(t);
    }, [resendIn]);

    function channelLabel() {
        return channel === 'email' ? 'email' : 'SMS';
    }

    async function sendCode(purpose: 'registration' | 'login' | 'password_reset') {
        await postJson(route('auth.code.send'), { identifier, purpose });
        setResendIn(60);
        setCode('');
    }

    async function handleContinue(e: FormEvent) {
        e.preventDefault();
        if (busy) return;
        setBusy(true);
        setError('');

        try {
            const result = await postJson<IdentifyResponse>(route('auth.identify'), { identifier });
            setMasked(result.masked);
            setChannel(result.channel);
            setIdentifier(result.identifier);

            if (result.exists) {
                setStep('password');
            } else {
                await sendCode('registration');
                setStep('register-code');
            }
        } catch (err) {
            setError(firstError(err));
        } finally {
            setBusy(false);
        }
    }

    function handlePasswordLogin(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError('');
        router.post(
            route('login'),
            { identifier, password, redirect: window.location.pathname + window.location.search },
            {
                onError: (errors) => setError(Object.values(errors)[0] ?? 'Sign in failed.'),
                onFinish: () => setBusy(false),
            },
        );
    }

    async function switchToCodeLogin() {
        setBusy(true);
        setError('');
        try {
            await sendCode('login');
            setStep('login-code');
        } catch (err) {
            setError(firstError(err));
        } finally {
            setBusy(false);
        }
    }

    async function switchToReset() {
        setBusy(true);
        setError('');
        try {
            await sendCode('password_reset');
            setPassword('');
            setPasswordConfirmation('');
            setStep('reset');
        } catch (err) {
            setError(firstError(err));
        } finally {
            setBusy(false);
        }
    }

    function submitCodeLogin(codeValue: string = code) {
        if (busy) return;
        setBusy(true);
        setError('');
        router.post(
            route('auth.code.login'),
            { identifier, code: codeValue, redirect: window.location.pathname + window.location.search },
            {
                onError: (errors) => {
                    setError(Object.values(errors)[0] ?? 'Sign in failed.');
                    setCode(''); // clear so the boxes reset and can auto-submit again
                },
                onFinish: () => setBusy(false),
            },
        );
    }

    async function submitRegisterCode(codeValue: string = code) {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await postJson(route('auth.code.verify'), { identifier, code: codeValue });
            setCode('');
            setStep('register-details');
        } catch (err) {
            setError(firstError(err));
            setCode('');
        } finally {
            setBusy(false);
        }
    }

    function handleRegister(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError('');
        router.post(
            route('register'),
            { name, password, password_confirmation: passwordConfirmation },
            {
                onError: (errors) => setError(Object.values(errors)[0] ?? 'Registration failed.'),
                onFinish: () => setBusy(false),
            },
        );
    }

    function handleReset(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError('');
        router.post(
            route('auth.password.reset'),
            { identifier, code, password, password_confirmation: passwordConfirmation },
            {
                onError: (errors) => setError(Object.values(errors)[0] ?? 'Reset failed.'),
                onFinish: () => setBusy(false),
            },
        );
    }

    async function handleResend(purpose: 'registration' | 'login' | 'password_reset') {
        if (resendIn > 0 || busy) return;
        setBusy(true);
        setError('');
        try {
            await sendCode(purpose);
        } catch (err) {
            setError(firstError(err));
        } finally {
            setBusy(false);
        }
    }

    const inputClasses =
        'w-full rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm text-slate-900 placeholder:text-gray-400 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-600/10';
    const primaryButton =
        'w-full rounded-full bg-brand-600 py-2.5 text-sm font-bold text-white transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50';
    const linkButton = 'text-sm text-brand-600 underline-offset-2 hover:underline';

    return (
        <div>
            {/* Trust badge (AliExpress-style) */}
            <p className="flex items-center justify-center gap-1.5 text-xs text-emerald-600">
                <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                    <path
                        fillRule="evenodd"
                        d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z"
                        clipRule="evenodd"
                    />
                </svg>
                Your information is protected
            </p>

            {error !== '' && (
                <p className="mt-3 rounded-xl bg-red-50 px-4 py-2 text-sm text-red-700" role="alert">
                    {error}
                </p>
            )}

            {step === 'entry' && (
                <form onSubmit={handleContinue} className="mt-4">
                    <label htmlFor="auth-identifier" className="sr-only">
                        Email or phone number
                    </label>
                    <input
                        id="auth-identifier"
                        type="text"
                        value={identifier}
                        onChange={(e) => setIdentifier(e.target.value)}
                        placeholder="Email or phone number"
                        autoComplete="username"
                        required
                        className={inputClasses}
                    />
                    <button type="submit" disabled={busy || identifier.trim() === ''} className={`${primaryButton} mt-3`}>
                        {busy ? 'Checking…' : 'Continue'}
                    </button>

                    <div className="my-5 flex items-center gap-3 text-xs text-gray-400">
                        <span className="h-px flex-1 bg-gray-200" />
                        Or continue with
                        <span className="h-px flex-1 bg-gray-200" />
                    </div>

                    <div className="space-y-2.5">
                        <a
                            href={route('auth.social.redirect', { provider: 'google' })}
                            className="flex w-full items-center justify-center gap-3 rounded-full border border-gray-200 py-2.5 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-300 hover:bg-slate-50"
                        >
                            <GoogleIcon /> Google
                        </a>
                        <a
                            href={route('auth.social.redirect', { provider: 'facebook' })}
                            className="flex w-full items-center justify-center gap-3 rounded-full border border-gray-200 py-2.5 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-300 hover:bg-slate-50"
                        >
                            <FacebookIcon /> Facebook
                        </a>
                    </div>

                    <p className="mt-5 text-center text-[11px] leading-relaxed text-gray-500">
                        By continuing, you confirm that you have read and accepted our Terms of Service and
                        Privacy Policy. FirstMarket is not a loan app — no cash withdrawal.
                    </p>
                </form>
            )}

            {step === 'password' && (
                <form onSubmit={handlePasswordLogin} className="mt-4">
                    <StepHeader onBack={() => setStep('entry')} title={`Welcome back — ${masked}`} />
                    <input
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="Password"
                        autoComplete="current-password"
                        autoFocus
                        required
                        className={`${inputClasses} mt-3`}
                    />
                    <button type="submit" disabled={busy || password === ''} className={`${primaryButton} mt-3`}>
                        {busy ? 'Signing in…' : 'Sign in'}
                    </button>
                    <div className="mt-4 flex items-center justify-between">
                        <button type="button" onClick={switchToCodeLogin} disabled={busy} className={linkButton}>
                            Sign in with a code instead
                        </button>
                        <button type="button" onClick={switchToReset} disabled={busy} className={linkButton}>
                            Forgot password?
                        </button>
                    </div>
                </form>
            )}

            {step === 'login-code' && (
                <form onSubmit={(e) => { e.preventDefault(); submitCodeLogin(); }} className="mt-4">
                    <StepHeader
                        onBack={() => setStep('password')}
                        title={`Enter the 6-digit code sent by ${channelLabel()} to ${masked}`}
                    />
                    <div className="mt-4">
                        <OtpInput
                            value={code}
                            onChange={setCode}
                            onComplete={(value) => submitCodeLogin(value)}
                            autoFocus
                            disabled={busy}
                            error={error !== ''}
                        />
                    </div>
                    {busy && <p className="mt-3 text-center text-sm text-gray-500">Verifying…</p>}
                    <ResendButton resendIn={resendIn} onResend={() => handleResend('login')} />
                </form>
            )}

            {step === 'register-code' && (
                <form onSubmit={(e) => { e.preventDefault(); submitRegisterCode(); }} className="mt-4">
                    <StepHeader
                        onBack={() => setStep('entry')}
                        title={`New to FirstMarket! Enter the 6-digit code sent by ${channelLabel()} to ${masked}`}
                    />
                    <div className="mt-4">
                        <OtpInput
                            value={code}
                            onChange={setCode}
                            onComplete={(value) => submitRegisterCode(value)}
                            autoFocus
                            disabled={busy}
                            error={error !== ''}
                        />
                    </div>
                    {busy && <p className="mt-3 text-center text-sm text-gray-500">Verifying…</p>}
                    <ResendButton resendIn={resendIn} onResend={() => handleResend('registration')} />
                </form>
            )}

            {step === 'register-details' && (
                <form onSubmit={handleRegister} className="mt-4">
                    <StepHeader onBack={() => setStep('entry')} title={`Almost done — create your account for ${masked}`} />
                    <input
                        type="text"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Full name"
                        autoComplete="name"
                        autoFocus
                        required
                        className={`${inputClasses} mt-3`}
                    />
                    <div className="mt-3">
                        <PasswordFields
                            password={password}
                            setPassword={setPassword}
                            confirmation={passwordConfirmation}
                            setConfirmation={setPasswordConfirmation}
                            inputClasses={inputClasses}
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={
                            busy ||
                            name.trim() === '' ||
                            !isPasswordValid(password) ||
                            !passwordsMatch(password, passwordConfirmation)
                        }
                        className={`${primaryButton} mt-3`}
                    >
                        {busy ? 'Creating account…' : 'Create account'}
                    </button>
                </form>
            )}

            {step === 'reset' && (
                <form onSubmit={handleReset} className="mt-4">
                    <StepHeader
                        onBack={() => setStep('password')}
                        title={`Reset password — enter the code sent by ${channelLabel()} to ${masked} and choose a new password`}
                    />
                    <div className="mt-4">
                        <OtpInput value={code} onChange={setCode} disabled={busy} error={error !== ''} />
                    </div>
                    <div className="mt-3">
                        <PasswordFields
                            password={password}
                            setPassword={setPassword}
                            confirmation={passwordConfirmation}
                            setConfirmation={setPasswordConfirmation}
                            inputClasses={inputClasses}
                            passwordPlaceholder="New password"
                            confirmPlaceholder="Confirm new password"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={
                            busy ||
                            code.length !== 6 ||
                            !isPasswordValid(password) ||
                            !passwordsMatch(password, passwordConfirmation)
                        }
                        className={`${primaryButton} mt-3`}
                    >
                        {busy ? 'Resetting…' : 'Reset password and sign in'}
                    </button>
                    <ResendButton resendIn={resendIn} onResend={() => handleResend('password_reset')} />
                </form>
            )}
        </div>
    );
}

function StepHeader({ onBack, title }: { onBack: () => void; title: string }) {
    return (
        <div className="flex items-start gap-2">
            <button
                type="button"
                onClick={onBack}
                aria-label="Go back"
                className="mt-0.5 shrink-0 rounded p-1 text-gray-500 hover:bg-gray-100"
            >
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
            </button>
            <p className="text-sm text-gray-700">{title}</p>
        </div>
    );
}

function ResendButton({ resendIn, onResend }: { resendIn: number; onResend: () => void }) {
    return (
        <p className="mt-3 text-center text-sm text-gray-500">
            {resendIn > 0 ? (
                <>({resendIn}s) Resend code</>
            ) : (
                <button type="button" onClick={onResend} className="text-brand-600 underline-offset-2 hover:underline">
                    Resend code
                </button>
            )}
        </p>
    );
}

function GoogleIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24">
            <path
                fill="#4285F4"
                d="M23.5 12.27c0-.85-.08-1.66-.22-2.45H12v4.64h6.45a5.52 5.52 0 0 1-2.4 3.62v3h3.88c2.27-2.1 3.57-5.17 3.57-8.81Z"
            />
            <path
                fill="#34A853"
                d="M12 24c3.24 0 5.96-1.07 7.94-2.91l-3.88-3.01c-1.08.72-2.45 1.15-4.06 1.15-3.13 0-5.78-2.11-6.72-4.95H1.27v3.11A12 12 0 0 0 12 24Z"
            />
            <path
                fill="#FBBC05"
                d="M5.28 14.28a7.2 7.2 0 0 1 0-4.56V6.61H1.27a12 12 0 0 0 0 10.78l4.01-3.11Z"
            />
            <path
                fill="#EA4335"
                d="M12 4.77c1.76 0 3.35.61 4.6 1.8l3.44-3.44A11.98 11.98 0 0 0 1.27 6.6l4.01 3.11C6.22 6.88 8.87 4.77 12 4.77Z"
            />
        </svg>
    );
}

function FacebookIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="#1877F2">
            <path d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.95.92-1.95 1.87V12h3.32l-.53 3.47h-2.79v8.38A12 12 0 0 0 24 12Z" />
        </svg>
    );
}
