import { Button } from '@/Components/ui/Button';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

interface Props {
    status?: string;
    [key: string]: unknown;
}

export default function VerifyEmail() {
    const { status } = usePage<Props>().props;
    const { post, processing } = useForm({});

    return (
        <GuestLayout>
            <Head title="Verify your email" />

            <h1 className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">Verify your email address</h1>
            <p className="mb-6 text-sm text-gray-600 dark:text-gray-400">
                We sent a verification link to your email address. Click the link in that message to confirm your
                email.
            </p>

            {status === 'verification-link-sent' && (
                <p className="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-800 dark:bg-green-900/40 dark:text-green-300">
                    A fresh verification link has been sent to your email address.
                </p>
            )}

            <Button
                disabled={processing}
                className="w-full"
                onClick={() => post(route('verification.send'))}
            >
                Resend verification email
            </Button>

            <p className="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">
                <Link href={route('dashboard')} className="font-medium text-brand-600 hover:underline">
                    Continue to dashboard
                </Link>
            </p>
        </GuestLayout>
    );
}
