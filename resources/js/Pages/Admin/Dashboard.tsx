import StatCard from '@/Components/domain/admin/StatCard';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { Head, Link, usePage } from '@inertiajs/react';
import { MessagesSquare, PackageCheck, Store, Truck } from 'lucide-react';

interface AdminDashboardProps {
    pendingVendors: number;
    pendingProducts: number;
}

export default function AdminDashboard({ pendingVendors, pendingProducts }: AdminDashboardProps) {
    const { auth } = usePage<PageProps>().props;
    const firstName = (auth.user?.name ?? '').split(/\s+/)[0];

    const stats = [
        {
            label: 'Pending Vendor Approvals',
            value: pendingVendors,
            icon: Store,
            accent: 'bg-amber-100 text-amber-600',
            tone: 'amber' as const,
            href: route('admin.vendors.index'),
        },
        {
            label: 'Pending Product Approvals',
            value: pendingProducts,
            icon: PackageCheck,
            accent: 'bg-brand-50 text-brand-600',
            tone: 'brand' as const,
            href: route('admin.products.index'),
        },
        {
            label: 'Open Support Tickets',
            value: 0,
            icon: MessagesSquare,
            accent: 'bg-violet-100 text-violet-600',
            tone: 'violet' as const,
            hint: 'Ticket queue arrives in Sprint 7.',
        },
        {
            label: 'Orders In Progress',
            value: 0,
            icon: Truck,
            accent: 'bg-emerald-100 text-emerald-600',
            tone: 'emerald' as const,
            hint: 'Order pipeline arrives in a later sprint.',
        },
    ];

    return (
        <AdminLayout>
            <Head title="Admin Dashboard" />

            <Reveal>
                <div className="relative overflow-hidden rounded-3xl bg-gradient-to-r from-brand-700 via-brand-600 to-brand-900 px-6 py-8 text-white sm:px-10">
                    <span className="pointer-events-none absolute -right-5 -top-8 select-none text-[9rem] leading-none opacity-10" aria-hidden="true">📊</span>
                    <div className="relative z-[1] flex flex-wrap items-end justify-between gap-5">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-yellow">Staff workspace</p>
                        <h1 className="mt-2 text-2xl font-extrabold tracking-tight text-white sm:text-3xl">
                            Overview
                        </h1>
                        <p className="mt-1 text-sm text-white/80">
                            Welcome back{firstName ? `, ${firstName}` : ''} — here's what needs your attention.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href={route('admin.vendors.index')}
                            className="rounded-full bg-brand-yellow px-4 py-2 text-xs font-bold text-brand-900 transition hover:bg-yellow-300 hover:shadow-lg active:scale-95"
                        >
                            Review vendors
                        </Link>
                        <Link
                            href={route('admin.products.index')}
                            className="rounded-full border border-white/40 px-4 py-2 text-xs font-semibold text-white transition hover:border-white hover:bg-white/10 active:scale-95"
                        >
                            Review products
                        </Link>
                    </div>
                </div>
                </div>
            </Reveal>

            <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((stat, index) => (
                    <Reveal key={stat.label} delay={index * 100}>
                        <StatCard {...stat} light />
                    </Reveal>
                ))}
            </div>

            <Reveal delay={300}>
                <p className="mt-8 text-sm text-gray-500">
                    User management, vendor approvals, and reporting arrive in later sprints.
                </p>
            </Reveal>
        </AdminLayout>
    );
}
