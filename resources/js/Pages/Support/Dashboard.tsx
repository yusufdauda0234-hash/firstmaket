import { Card } from '@/Components/ui/Card';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';

export default function SupportDashboard() {
    return (
        <AdminLayout>
            <Head title="Support Dashboard" />

            <h1 className="mb-6 text-2xl font-semibold text-gray-900 dark:text-gray-100">Support Queue</h1>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Open Tickets</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Hotline Requests</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
            </div>

            <p className="mt-8 text-sm text-gray-500 dark:text-gray-400">
                Ticket queue and customer order/plan lookup arrive in Sprint 7.
            </p>
        </AdminLayout>
    );
}
