import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import StaffAuthLayout, { lightInput, lightLabel } from '@/Layouts/StaffAuthLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function AdminLogin() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.login'));
    };

    return (
        <StaffAuthLayout
            title="Sign in"
            subtitle="Enter your credentials to continue."
        >
            <Head title="Sign in" />

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <Label htmlFor="email" className={lightLabel}>
                        Email
                    </Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        className={lightInput}
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />
                    <InputError message={errors.email} />
                </div>

                <div>
                    <Label htmlFor="password" className={lightLabel}>
                        Password
                    </Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        className={lightInput}
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        required
                    />
                    <InputError message={errors.password} />
                </div>

                <label className="flex cursor-pointer items-center gap-2 text-sm text-gray-600">
                    <input
                        type="checkbox"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                        className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                    />
                    Keep me signed in on this device
                </label>

                <Button
                    type="submit"
                    disabled={processing}
                    className="w-full transition hover:shadow-lg focus-visible:ring-2 focus-visible:ring-brand-yellow active:scale-[0.98]"
                >
                    {processing ? 'Signing in…' : 'Log in'}
                </Button>
            </form>

            <p className="mt-6 text-center text-xs text-gray-400">
                Need help signing in? Contact support for assistance.
            </p>
        </StaffAuthLayout>
    );
}
