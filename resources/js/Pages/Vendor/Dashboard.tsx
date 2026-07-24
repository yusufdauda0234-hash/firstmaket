import StatCard from '@/Components/domain/admin/StatCard';
import { Badge, statusTone } from '@/Components/ui/Badge';
import Reveal from '@/Components/ui/Reveal';
import VendorLayout from '@/Layouts/VendorLayout';
import { PageProps } from '@/Types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Banknote, CheckCircle2, Clock4 } from 'lucide-react';

interface Props extends PageProps {
    businessName: string | null;
    vendorStatus: string | null;
    rejectionReason: string | null;
    productCounts: {
        draft: number;
        pendingApproval: number;
        approved: number;
        rejected: number;
        delisted: number;
    };
}

const statusMessages: Record<string, string> = {
    pending: 'Your application is being reviewed. You will be able to list products once it is approved.',
    approved: 'Your business is approved. You can now list products and submit them for review.',
    rejected: 'Your application was rejected. See the reason below and contact support to reapply.',
    suspended: 'Your vendor account is suspended. Contact support for assistance.',
    banned: 'Your vendor account has been banned.',
};

export default function VendorDashboard() {
    const { auth, businessName, vendorStatus, rejectionReason, productCounts } = usePage<Props>().props;
    const firstName = (auth.user?.name ?? '').split(/\s+/)[0];
    const isApproved = vendorStatus === 'approved';

    // Three wide cards so big numbers (and ₦ amounts) never get squeezed;
    // secondary counts live in the footer line of the listings card.
    const rejectedTotal = productCounts.rejected + productCounts.delisted;
    const stats = [
        {
            label: 'Live on the marketplace',
            value: productCounts.approved,
            icon: CheckCircle2,
            accent: 'bg-emerald-100 text-emerald-600',
            tone: 'emerald' as const,
            href: route('vendor.products.index', { status: 'approved' }),
            hint: `${productCounts.draft} draft${productCounts.draft === 1 ? '' : 's'} · ${rejectedTotal} rejected / delisted`,
        },
        {
            label: 'Pending approval',
            value: productCounts.pendingApproval,
            icon: Clock4,
            accent: 'bg-amber-100 text-amber-600',
            tone: 'amber' as const,
            href: route('vendor.products.index', { status: 'pending_approval' }),
            hint: 'Reviewed by the FirstMarketteam.',
        },
        {
            label: 'Earnings',
            value: '₦0.00',
            icon: Banknote,
            accent: 'bg-brand-50 text-brand-600',
            tone: 'brand' as const,
            hint: 'Wallet and payouts arrive with Sprint 4.',
        },
    ];

    return (
        <VendorLayout>
            <Head title="Vendor Dashboard" />

            {/* Hero band — same brand gradient language as the home page */}
            <Reveal>
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-brand-700 via-brand-600 to-brand-900 px-6 py-8 text-white sm:px-10">
                    <span
                        className="pointer-events-none absolute -right-6 -top-10 select-none text-[10rem] leading-none opacity-10"
                        aria-hidden="true"
                    >
                        🏪
                    </span>
                    <div className="relative z-[1] flex flex-wrap items-end justify-between gap-6">
                        <div>
                            <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-yellow">
                                Vendor Center
                            </p>
                            <h1 className="mt-2 text-2xl font-extrabold leading-tight sm:text-3xl">
                                Welcome back{firstName ? `, ${firstName}` : ''} 👋
                            </h1>
                            <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-white/80">
                                {businessName ?? 'Your store'}
                                {vendorStatus && (
                                    <Badge light tone={statusTone(vendorStatus)}>{vendorStatus}</Badge>
                                )}
                            </p>
                        </div>
                        {isApproved && (
                            <div className="flex flex-wrap gap-3">
                                <Link
                                    href={route('vendor.products.index', { new: 1 })}
                                    className="rounded-full bg-brand-yellow px-6 py-2.5 text-sm font-bold text-brand-900 transition hover:-translate-y-0.5 hover:bg-yellow-300 hover:shadow-lg active:scale-95"
                                >
                                    + Add product
                                </Link>
                                <Link
                                    href={route('vendor.products.index')}
                                    className="rounded-full border border-white/40 px-6 py-2.5 text-sm font-semibold text-white transition hover:border-white hover:bg-white/10 active:scale-95"
                                >
                                    View listings
                                </Link>
                            </div>
                        )}
                    </div>
                </div>
            </Reveal>

            {vendorStatus && statusMessages[vendorStatus] && !isApproved && (
                <Reveal delay={100}>
                    <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                        <p className="text-sm text-amber-800">{statusMessages[vendorStatus]}</p>
                        {vendorStatus === 'rejected' && rejectionReason && (
                            <p className="mt-2 text-sm font-medium text-red-600">Reason: {rejectionReason}</p>
                        )}
                    </div>
                </Reveal>
            )}

            <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {stats.map((stat, index) => (
                    <Reveal key={stat.label} delay={index * 90}>
                        <StatCard {...stat} light />
                    </Reveal>
                ))}
            </div>

            <Reveal delay={300}>
                <p className="mt-8 text-sm text-gray-500">
                    Orders, delivery tracking, and payouts arrive in later sprints — listings are the
                    focus for now.
                </p>
            </Reveal>
        </VendorLayout>
    );
}
