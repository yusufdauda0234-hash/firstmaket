import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { PageProps } from '@/Types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, MailCheck, Store } from 'lucide-react';
import { FormEventHandler } from 'react';

/**
 * "I forgot my password" for vendors.
 *
 * Previously a vendor in this position had to contact support and ask staff
 * to send them a reset. This is the same email, asked for by the vendor.
 *
 * The confirmation is deliberately the same whether or not the address
 * belongs to a vendor account — otherwise the form becomes a way to discover
 * which addresses sell on FirstMaket, and a list of verified sellers is worth
 * having to anyone running a scam against them.
 */
export default function VendorForgotPassword() {
    const { flash } = usePage<PageProps>().props;
    const form = useForm({ email: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('vendor.password.email'), { onSuccess: () => form.reset('email') });
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
                    Enter the email on your vendor account and we will send you a link to set a new
                    password.
                </p>

                {flash?.success && (
                    <p className="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm leading-relaxed text-emerald-800">
                        {flash.success}
                    </p>
                )}

                <form onSubmit={submit} className="mt-6 space-y-4">
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Email</span>
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            autoComplete="username"
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
                    href={route('vendor.login')}
                    className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:text-brand-700"
                >
                    <ArrowLeft className="h-4 w-4" /> Back to sign in
                </Link>

                <p className="mt-5 flex items-center gap-1.5 border-t border-gray-100 pt-4 text-xs text-gray-400">
                    <Store className="h-3.5 w-3.5" /> FirstMaket Vendor Center
                </p>
            </div>
        </div>
    );
}
