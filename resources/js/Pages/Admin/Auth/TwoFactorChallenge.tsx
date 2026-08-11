import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Head, useForm, usePage } from '@inertiajs/react';
import { KeyRound, ShieldCheck, Smartphone } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Props {
    recoveryCodesLeft: number;
    [key: string]: unknown;
}

export default function TwoFactorChallenge() {
    const { recoveryCodesLeft } = usePage<Props>().props;
    const [usingRecovery, setUsingRecovery] = useState(false);

    const form = useForm({ code: '', remember_device: false });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('admin.two-factor.challenge.store'), { onError: () => form.setData('code', '') });
    };

    const abandon = () => form.post(route('admin.two-factor.challenge.abandon'));

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-900 p-4">
            <Head title="Two-factor authentication" />

            <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl">
                <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                    <ShieldCheck className="h-6 w-6" />
                </span>

                <h1 className="mt-4 text-xl font-extrabold text-gray-900">Two-factor authentication</h1>
                <p className="mt-1.5 text-sm text-gray-500">
                    {usingRecovery
                        ? 'Enter a saved recovery code to continue.'
                        : 'Enter the 6-digit code from your authenticator app.'}
                </p>

                <form onSubmit={submit} className="mt-6">
                    <label htmlFor="code" className="mb-2 block text-sm font-semibold text-gray-800">
                        {usingRecovery ? 'Recovery code' : 'Authentication code'}
                    </label>
                    <Input
                        id="code"
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value)}
                        inputMode={usingRecovery ? 'text' : 'numeric'}
                        autoComplete="one-time-code"
                        placeholder={usingRecovery ? 'abcde-12345' : '123456'}
                        aria-label={usingRecovery ? 'Recovery code' : '6-digit code'}
                        className={
                            usingRecovery
                                ? 'h-14 rounded-xl border-2 border-gray-300 bg-gray-50 px-4 text-center font-mono text-lg tracking-[0.18em] shadow-sm placeholder:text-gray-400 focus:border-brand-600 focus:bg-white focus:ring-4 focus:ring-brand-100'
                                : 'h-16 rounded-xl border-2 border-gray-300 bg-gray-50 text-center font-mono text-2xl tracking-[0.45em] shadow-sm placeholder:text-gray-400 focus:border-brand-600 focus:bg-white focus:ring-4 focus:ring-brand-100'
                        }
                        maxLength={usingRecovery ? 32 : 6}
                        autoFocus
                        required
                    />
                    <InputError message={form.errors.code} className="mt-2" />

                    {!usingRecovery && (
                        <label className="mt-4 flex items-start gap-2.5">
                            <input
                                type="checkbox"
                                checked={form.data.remember_device}
                                onChange={(e) => form.setData('remember_device', e.target.checked)}
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                            />
                            <span className="text-sm">
                                <span className="font-semibold text-gray-800">Trust this device for 30 days</span>
                                <span className="block text-xs text-gray-500">
                                    Use only on a private device.
                                </span>
                            </span>
                        </label>
                    )}

                    <button
                        type="submit"
                        disabled={form.processing || form.data.code === ''}
                        className="mt-5 w-full rounded-full bg-brand-600 py-3 text-sm font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                    >
                        {form.processing ? 'Verifying…' : 'Verify and sign in'}
                    </button>
                </form>

                <div className="mt-5 space-y-2 border-t border-gray-100 pt-4">
                    <button
                        type="button"
                        onClick={() => {
                            setUsingRecovery(!usingRecovery);
                            form.setData('code', '');
                            form.clearErrors();
                        }}
                        className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:text-brand-700"
                    >
                        {usingRecovery ? <><Smartphone className="h-3.5 w-3.5" /> Use authenticator app</> : <><KeyRound className="h-3.5 w-3.5" /> Use recovery code</>}
                    </button>

                    {usingRecovery && (
                        <p className="text-xs text-gray-400">
                            {recoveryCodesLeft > 0
                                ? `${recoveryCodesLeft} recovery code${recoveryCodesLeft === 1 ? '' : 's'} remaining.`
                                : 'No recovery codes remain. Contact support for assistance.'}
                        </p>
                    )}

                    <button
                        type="button"
                        onClick={abandon}
                        className="flex w-full items-center justify-center rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-semibold text-gray-600 transition hover:border-gray-300 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    );
}
