import { Card } from '@/Components/ui/Card';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

export default function CustomerDashboard() {
    return (
        <AppLayout>
            <Head title="Dashboard" />

            <h1 className="mb-6 text-2xl font-semibold text-gray-900 dark:text-gray-100">Your Dashboard</h1>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Open Savings</p>
                    <p className="mt-2 text-2xl font-semibold">₦0.00</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Active Plans</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Total Saved</p>
                    <p className="mt-2 text-2xl font-semibold">₦0.00</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Orders</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
            </div>

            <p className="mt-8 text-sm text-gray-500 dark:text-gray-400">
                Wallet, savings, and order data will populate here starting in Sprint 4/5.
            </p>
        </AppLayout>
    );
}
