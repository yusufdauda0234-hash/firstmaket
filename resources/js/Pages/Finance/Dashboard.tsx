import { Card } from '@/Components/ui/Card';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';

export default function FinanceDashboard() {
    return (
        <AdminLayout>
            <Head title="Finance Dashboard" />

            <h1 className="mb-6 text-2xl font-semibold text-gray-900 dark:text-gray-100">Reconciliation</h1>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Unmatched Settlements</p>
                    <p className="mt-2 text-2xl font-semibold">0</p>
                </Card>
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">Pending Affiliate Payouts</p>
                    <p className="mt-2 text-2xl font-semibold">₦0.00</p>
                </Card>
            </div>

            <p className="mt-8 text-sm text-gray-500 dark:text-gray-400">
                Paystack settlement reconciliation arrives in Sprint 4.
            </p>
        </AdminLayout>
    );
}
