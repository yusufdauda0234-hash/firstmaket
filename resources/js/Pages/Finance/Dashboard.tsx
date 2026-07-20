import StatCard from '@/Components/domain/admin/StatCard';
import { Button } from '@/Components/ui/Button';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';
import { Banknote, Scale } from 'lucide-react';

export default function FinanceDashboard() {
    return (
        <AdminLayout>
            <Head title="Finance Dashboard" />

            <PageHeader
                eyebrow="Finance"
                title="Reconciliation"
                description="Paystack settlements and affiliate payouts at a glance."
                actions={
                    <Link href={route('admin.reconciliation.index')}>
                        <Button className="active:scale-95">
                            <Scale className="mr-2 h-4 w-4" /> Open reconciliation
                        </Button>
                    </Link>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Reveal>
                    <Link href={route('admin.reconciliation.index')} className="block">
                        <StatCard
                            label="Settlement reconciliation"
                            value="Open"
                            icon={Scale}
                            accent="bg-amber-100 text-amber-600"
                            tone="amber"
                            light
                            hint="Import a Paystack settlement batch to reconcile."
                        />
                    </Link>
                </Reveal>
                <Reveal delay={100}>
                    <StatCard
                        label="Pending Affiliate Payouts"
                        value="₦0.00"
                        icon={Banknote}
                        accent="bg-emerald-100 text-emerald-600"
                        tone="emerald"
                        light
                        hint="Affiliate payouts arrive in a later sprint."
                    />
                </Reveal>
            </div>
        </AdminLayout>
    );
}
