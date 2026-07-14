import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props {
    otpAuthUrl: string;
    secret: string;
}

export default function TwoFactorSetup({ otpAuthUrl, secret }: Props) {
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.two-factor.confirm'));
    };

    return (
        <GuestLayout>
            <Head title="Set up two-factor authentication" />

            <h1 className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Two-factor authentication required
            </h1>
            <p className="mb-6 text-sm text-gray-600 dark:text-gray-400">
                Administrator, Super Administrator, and Finance Officer accounts must enable 2FA before continuing.
                Add this account to an authenticator app (Google Authenticator, Authy, 1Password) using the key
                below, then enter the 6-digit code it generates.
            </p>

            <div className="mb-6 rounded-md bg-gray-100 p-3 font-mono text-sm break-all dark:bg-gray-800">
                {secret}
            </div>

            <a href={otpAuthUrl} className="mb-6 block text-sm text-brand-600 hover:underline">
                Open in authenticator app
            </a>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <Label htmlFor="code">6-digit code</Label>
                    <Input
                        id="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        required
                    />
                    <InputError message={errors.code} />
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    Confirm and continue
                </Button>
            </form>
        </GuestLayout>
    );
}
