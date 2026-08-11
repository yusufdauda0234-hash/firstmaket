import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { PageProps } from '@/Types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, MailCheck, ShieldCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

/**
 * "I forgot my password" for staff.
 *
 * The confirmation is deliberately the same whether or not the address
 * belongs to a staff account. Saying "no such account" would turn this form
 * into a way to work out which addresses are FirstMaket staff — exactly the
 * list somebody planning a phishing run would want.
 */
export default function ForgotPassword() {
    const { flash } = usePage<PageProps>().props;
    const form = useForm({ email: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('admin.password.email'), { onSuccess: () => form.reset('email') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-900 p-4">
            <Head title="Forgot password" />

            <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl">
                <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                    <MailCheck className="h-6 w-6" />
                </span>

                <h1 className="mt-4 text-xl font-extrabold text-gray-900">Forgot your password?</h1>
                <p className="mt-1.5 text-sm text-gray-500">
                    Enter your work email and we will send you a link to set a new one.
                </p>

                {flash?.success && (
                    <p className="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm leading-relaxed text-emerald-800">
                        {flash.success}
                    </p>
                )}

                <form onSubmit={submit} className="mt-6 space-y-4">
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Work email</span>
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            autoComplete="username"
                            placeholder="you@firstmaket.com"
                            autoFocus
                            required
                        />
                        <InputError message={form.errors.email} className="mt-1" />
                    </label>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-full bg-brand-600 py-3 text-sm font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                    >
                        {form.processing ? 'Sending…' : 'Email me a link'}
                    </button>
                </form>

                <Link
                    href={route('admin.login')}
                    className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:text-brand-700"
                >
                    <ArrowLeft className="h-4 w-4" /> Back to sign in
                </Link>

                <p className="mt-5 flex items-center gap-1.5 border-t border-gray-100 pt-4 text-xs text-gray-400">
                    <ShieldCheck className="h-3.5 w-3.5" /> FirstMaket staff workspace
                </p>
            </div>
        </div>
    );
}
