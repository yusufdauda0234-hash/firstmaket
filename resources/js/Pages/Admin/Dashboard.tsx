import { Card } from '@/Components/ui/Card';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';

export default function AdminDashboard() {
    return (
        <AdminLayout>
            <Head title="Admin Dashboard" />

            <h1 className="mb-6 text-2xl font-semibold text-gray-900 dark:text-gray-100">Overview</h1>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Pending Vendor Approvals</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Pending Product Approvals</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Open Support Tickets</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Orders In Progress</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
            </div>

            <p className="mt-8 text-sm text-gray-500 dark:text-gray-400">
                User management, vendor approvals, and reporting arrive in later sprints.
            </p>
        </AdminLayout>
    );
}
