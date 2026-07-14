import { Card } from '@/Components/ui/Card';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';

export default function LogisticsDashboard() {
    return (
        <AdminLayout>
            <Head title="Logistics Dashboard" />

            <h1 className="mb-6 text-2xl font-semibold text-gray-900 dark:text-gray-100">Assigned Deliveries</h1>

            <Card>
                <p className="text-sm text-gray-500 dark:text-gray-400">Deliveries In Progress</p>
                <p className="mt-2 text-2xl font-semibold">0</p>
            </Card>

            <p className="mt-8 text-sm text-gray-500 dark:text-gray-400">
                Delivery status updates arrive in Sprint 6.
            </p>
        </AdminLayout>
    );
}
