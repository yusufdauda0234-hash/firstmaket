import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import StaffAuthLayout, { lightInput, lightLabel } from '@/Layouts/StaffAuthLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props {
    otpAuthUrl: string;
    secret: string;
    qrCodeSvg: string;
}

export default function TwoFactorSetup({ otpAuthUrl, secret, qrCodeSvg }: Props) {
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.two-factor.confirm'));
    };

    return (
        <StaffAuthLayout
            title="Two-factor authentication required"
            subtitle="Administrator, Super Administrator, and Finance Officer accounts must enable 2FA before continuing."
        >
            <Head title="Set up two-factor authentication" />

            <p className="text-center text-sm text-gray-500">
                Scan the QR code with an authenticator app (Google Authenticator, Authy, 1Password),
                then enter the 6-digit code it generates.
            </p>

            <div className="mt-5 flex justify-center">
                {/* Server-rendered SVG so the 2FA secret never leaves the app. */}
                <div
                    className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                    dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                />
            </div>

            <details className="mt-5 rounded-xl border border-gray-200 px-4 py-3">
                <summary className="cursor-pointer text-sm font-medium text-gray-600 transition-colors hover:text-gray-900">
                    Can't scan? Enter the key manually
                </summary>
                <div className="mt-3 break-all rounded-lg bg-gray-100 p-3 font-mono text-sm text-gray-800">
                    {secret}
                </div>
                <a href={otpAuthUrl} className="mt-2 block text-sm text-brand-600 hover:underline">
                    Open in authenticator app on this device
                </a>
            </details>

            <form onSubmit={submit} className="mt-6 space-y-4">
                <div>
                    <Label htmlFor="code" className={lightLabel}>
                        6-digit code
                    </Label>
                    <Input
                        id="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        placeholder="123456"
                        className={`${lightInput} text-center font-mono text-lg tracking-[0.5em]`}
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        required
                    />
                    <InputError message={errors.code} />
                </div>

                <Button
                    type="submit"
                    disabled={processing}
                    className="w-full transition hover:shadow-lg focus-visible:ring-2 focus-visible:ring-brand-yellow active:scale-[0.98]"
                >
                    {processing ? 'Verifying…' : 'Confirm and continue'}
                </Button>
            </form>
        </StaffAuthLayout>
    );
}
