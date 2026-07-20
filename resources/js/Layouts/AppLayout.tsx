import { Button } from '@/Components/ui/Button';
import { PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function AppLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<PageProps>().props;

    return (
        <div className="min-h-screen bg-gray-50 dark:bg-gray-950">
            <header className="border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
                    <div className="flex items-center gap-6">
                        <Link href={route('dashboard')} className="text-lg font-semibold text-brand-700 dark:text-brand-500">
                            FirstMarket
                        </Link>
                        <Link
                            href={route('wallet.index')}
                            className="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            Wallet
                        </Link>
                        <Link
                            href={route('identity.status')}
                            className="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            Verification
                        </Link>
                        {auth.user?.roles.includes('Vendor') && (
                            <a
                                href={route('vendor.dashboard')}
                                className="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                            >
                                Vendor Center
                            </a>
                        )}
                        <Link
                            href={route('account.settings')}
                            className="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            Settings
                        </Link>
                    </div>

                    <div className="flex items-center gap-4">
                        <span className="text-sm text-gray-600 dark:text-gray-400">{auth.user?.name}</span>
                        <Button variant="secondary" onClick={() => router.post(route('logout'))}>
                            Log out
                        </Button>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-6xl px-4 py-8">{children}</main>
        </div>
    );
}
