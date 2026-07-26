import StatCard from '@/Components/domain/admin/StatCard';
import VerifyPhoneModal from '@/Components/domain/auth/VerifyPhoneModal';
import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import Reveal from '@/Components/ui/Reveal';
import VendorLayout from '@/Layouts/VendorLayout';
import { PageProps } from '@/Types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Banknote, CheckCircle2, Clock4, ShieldCheck, X } from 'lucide-react';
import { useState } from 'react';

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
    const [verifyModalOpen, setVerifyModalOpen] = useState(false);
    const [phonePromptDismissed, setPhonePromptDismissed] = useState(false);
    const showPhonePrompt = auth.user && !auth.user.phoneVerified && !phonePromptDismissed;

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
            hint: 'Reviewed by the FirstMaket team.',
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

            {showPhonePrompt && (
                <Reveal delay={100}>
                    <div className="mt-4 flex items-center justify-between gap-4 rounded-2xl border border-brand-200 bg-brand-50 px-5 py-4">
                        <div className="flex items-center gap-3">
                            <ShieldCheck className="h-5 w-5 shrink-0 text-brand-600" />
                            <p className="text-sm text-brand-800">
                                Your phone number isn't verified yet — optional, but it helps us reach you about
                                orders.
                            </p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            <Button
                                variant="ghost"
                                className="text-sm text-brand-700 hover:bg-brand-100"
                                onClick={() => setVerifyModalOpen(true)}
                            >
                                Verify phone
                            </Button>
                            <button
                                type="button"
                                aria-label="Dismiss"
                                onClick={() => setPhonePromptDismissed(true)}
                                className="flex h-7 w-7 items-center justify-center rounded-full text-brand-400 hover:bg-brand-100 hover:text-brand-700"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </Reveal>
            )}

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

            {auth.user && (
                <VerifyPhoneModal
                    open={verifyModalOpen}
                    onClose={() => setVerifyModalOpen(false)}
                    phone={auth.user.phone}
                />
            )}
        </VendorLayout>
    );
}
