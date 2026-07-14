import { Card } from '@/Components/ui/Card';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

export default function VendorDashboard() {
    return (
        <AppLayout>
            <Head title="Vendor Dashboard" />

            <h1 className="mb-6 text-2xl font-semibold text-gray-900 dark:text-gray-100">Vendor Dashboard</h1>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Pending Approval</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Approved Listings</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Earnings</p>
                    <p className="mt-2 text-2xl font-semibold">₦0.00</p>
                </Card>
            </div>

            <p className="mt-8 text-sm text-gray-500 dark:text-gray-400">
                Product listing management arrives in Sprint 3.
            </p>
        </AppLayout>
    );
}
