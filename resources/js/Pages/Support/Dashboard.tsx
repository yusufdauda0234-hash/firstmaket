import StatCard from '@/Components/domain/admin/StatCard';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';
import { MessagesSquare, PhoneCall } from 'lucide-react';

export default function SupportDashboard() {
    return (
        <AdminLayout>
            <Head title="Support Dashboard" />

            <Reveal>
                <h1 className="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">
                    Support Queue
                </h1>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Tickets and hotline requests waiting on the support team.
                </p>
            </Reveal>

            <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Reveal>
                    <StatCard
                        label="Open Tickets"
                        value={0}
                        icon={MessagesSquare}
                        accent="bg-violet-100 text-violet-600"
                    />
                </Reveal>
                <Reveal delay={100}>
                    <StatCard
                        label="Hotline Requests"
                        value={0}
                        icon={PhoneCall}
                        accent="bg-emerald-100 text-emerald-600"
                    />
                </Reveal>
            </div>

            <Reveal delay={200}>
                <p className="mt-8 text-sm text-gray-500 dark:text-gray-400">
                    Ticket queue and customer order/plan lookup arrive in Sprint 7.
                </p>
            </Reveal>
        </AdminLayout>
    );
}
