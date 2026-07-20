import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props {
    phone: string;
    codeSendFailed: boolean;
    [key: string]: unknown;
}

export default function VerifyPhone() {
    const { phone, codeSendFailed } = usePage<Props>().props;
    const { data, setData, post, processing, errors } = useForm({ code: '' });
    const resendForm = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('phone.verify'));
    };

    return (
        <GuestLayout>
            <Head title="Verify your phone" />

            <h1 className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">Verify your phone number</h1>
            <p className="mb-6 text-sm text-gray-600 dark:text-gray-400">
                We sent a 6-digit code by SMS to <span className="font-medium">{phone}</span>. Enter it below to
                confirm your number.
            </p>

            {codeSendFailed && (
                <p className="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                    We could not send a new code right now — too many recent requests. Please wait a few minutes,
                    then use “Resend code”.
                </p>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <Label htmlFor="code">Verification code</Label>
                    <Input
                        id="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        required
                    />
                    <InputError message={errors.code} />
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    Verify phone number
                </Button>
            </form>

            <div className="mt-4 flex items-center justify-between text-sm">
                <span className="text-gray-600 dark:text-gray-400">Didn't get the code?</span>
                <Button
                    variant="ghost"
                    disabled={resendForm.processing}
                    onClick={() => resendForm.post(route('phone.resend'))}
                >
                    Resend code
                </Button>
            </div>
        </GuestLayout>
    );
}
