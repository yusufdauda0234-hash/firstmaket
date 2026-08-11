import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff, KeyRound, ShieldCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Props {
    token: string;
    email: string;
    [key: string]: unknown;
}

/**
 * Where a staff member lands from the "set your password" email — both when
 * their account is first created and whenever they reset it afterwards.
 *
 * Lives on the admin subdomain so the whole journey stays in the portal they
 * will actually be working in. The token in the URL is the credential, so
 * this page is guest-only.
 */
export default function ResetPassword() {
    const { token, email } = usePage<Props>().props;
    const [visible, setVisible] = useState(false);

    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('admin.password.update'), {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-900 p-4">
            <Head title="Set your password" />

            <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl">
                <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                    <KeyRound className="h-6 w-6" />
                </span>

                <h1 className="mt-4 text-xl font-extrabold text-gray-900">Set your password</h1>
                <p className="mt-1.5 text-sm text-gray-500">
                    Choose a password for your FirstMaket staff account.
                </p>

                <form onSubmit={submit} className="mt-6 space-y-4">
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Work email</span>
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            // Comes from the link; editable only so somebody can
                            // correct a mangled address rather than being stuck.
                            autoComplete="username"
                            required
                        />
                        <InputError message={form.errors.email} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">New password</span>
                        <span className="relative block">
                            <Input
                                type={visible ? 'text' : 'password'}
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                autoComplete="new-password"
                                className="pr-10"
                                autoFocus
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setVisible((v) => !v)}
                                aria-label={visible ? 'Hide password' : 'Show password'}
                                className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1.5 text-gray-400 transition hover:text-gray-600"
                            >
                                {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                            </button>
                        </span>
                        <InputError message={form.errors.password} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Confirm password</span>
                        <Input
                            type={visible ? 'text' : 'password'}
                            value={form.data.password_confirmation}
                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        <InputError message={form.errors.password_confirmation} className="mt-1" />
                    </label>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-full bg-brand-600 py-3 text-sm font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                    >
                        {form.processing ? 'Saving…' : 'Save password and continue'}
                    </button>
                </form>

                <p className="mt-5 border-t border-gray-100 pt-4 text-xs leading-relaxed text-gray-400">
                    A staff account can reach customer records and money. Use a password you
                    do not use anywhere else, and never share this link.
                </p>

                <p className="mt-3 flex items-center gap-1.5 text-xs text-gray-400">
                    <ShieldCheck className="h-3.5 w-3.5" /> FirstMaket staff workspace
                </p>
            </div>
        </div>
    );
}
