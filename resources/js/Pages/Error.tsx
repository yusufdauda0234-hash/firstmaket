import { Button } from '@/Components/ui/Button';
import { Head, Link } from '@inertiajs/react';

interface Props {
    status: number;
    title: string;
    message: string;
    homeUrl: string;
}

export default function Error({ status, title, message, homeUrl }: Props) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 dark:bg-gray-950">
            <Head title={title} />

            <div className="mb-8 text-xl font-semibold text-brand-700 dark:text-brand-500">FirstMarket</div>

            <div className="w-full max-w-md rounded-lg border border-gray-200 bg-white p-8 text-center shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p className="text-5xl font-bold text-gray-300 dark:text-gray-700">{status}</p>
                <h1 className="mt-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{title}</h1>
                <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">{message}</p>

                <div className="mt-6 flex justify-center gap-3">
                    <Link href={homeUrl}>
                        <Button variant="secondary">Go to homepage</Button>
                    </Link>
                    <Button variant="ghost" onClick={() => window.history.back()}>
                        Go back
                    </Button>
                </div>
            </div>
        </div>
    );
}
