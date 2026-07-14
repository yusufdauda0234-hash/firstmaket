import { Button } from '@/Components/ui/Button';
import { PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function AdminLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<PageProps>().props;

    return (
        <div className="min-h-screen bg-gray-100 dark:bg-gray-950">
            <header className="border-b border-gray-300 bg-gray-900 text-white">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
                    <Link href={route('admin.dashboard')} className="text-lg font-semibold">
                        FirstMarket Admin
                    </Link>

                    <div className="flex items-center gap-4">
                        <span className="text-sm text-gray-300">
                            {auth.user?.name} · {auth.user?.roles.join(', ')}
                        </span>
                        <Button variant="secondary" onClick={() => router.post(route('admin.logout'))}>
                            Log out
                        </Button>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-6xl px-4 py-8">{children}</main>
        </div>
    );
}
