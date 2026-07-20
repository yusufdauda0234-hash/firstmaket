import StatCard from '@/Components/domain/admin/StatCard';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';
import { Truck } from 'lucide-react';

export default function LogisticsDashboard() {
    return (
        <AdminLayout>
            <Head title="Logistics Dashboard" />

            <Reveal>
                <h1 className="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">
                    Assigned Deliveries
                </h1>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Deliveries currently assigned to you.
                </p>
            </Reveal>

            <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Reveal>
                    <StatCard
                        label="Deliveries In Progress"
                        value={0}
                        icon={Truck}
                        accent="bg-brand-50 text-brand-600"
                    />
                </Reveal>
            </div>

            <Reveal delay={200}>
                <p className="mt-8 text-sm text-gray-500 dark:text-gray-400">
                    Delivery status updates arrive in Sprint 6.
                </p>
            </Reveal>
        </AdminLayout>
    );
}
