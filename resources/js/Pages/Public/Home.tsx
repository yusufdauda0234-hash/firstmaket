import { Button } from '@/Components/ui/Button';
import { Head, Link } from '@inertiajs/react';

export default function Home() {
    return (
        <>
            <Head title="Home" />

            <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 text-center dark:bg-gray-950">
                <h1 className="text-3xl font-bold text-brand-700 dark:text-brand-500">FirstMarket</h1>
                <p className="mt-2 max-w-md text-gray-600 dark:text-gray-400">
                    Pay small small, collect with peace of mind.
                </p>

                <div className="mt-8 flex gap-4">
                    <Link href={route('login')}>
                        <Button variant="secondary">Log in</Button>
                    </Link>
                    <Link href={route('register')}>
                        <Button>Create account</Button>
                    </Link>
                </div>
            </div>
        </>
    );
}
