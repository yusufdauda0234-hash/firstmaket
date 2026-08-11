import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, router, useForm } from '@inertiajs/react';
import { BarChart3, Check, Copy, Landmark, Link2, ShieldCheck, Wallet } from 'lucide-react';
import type { ComponentType } from 'react';
import { useState } from 'react';

type StatRow = [string, string | number, ComponentType<{ className?: string }>];

interface AffiliateLink {
    id: number;
    label: string;
    campaign: string | null;
    status: string;
    expiresAt: string | null;
    url: string;
    clicks: number;
    signups: number;
}

interface PayoutRow {
    id: number;
    amountKobo: number;
    status: string;
    rejectionReason: string | null;
    failureReason: string | null;
    paidAt: string | null;
    period: string;
}

interface Props {
    application: { displayName: string; status: string; rejectionReason: string | null; suspensionReason: string | null } | null;
    links: AffiliateLink[];
    stats: { clicks: number; signups: number; conversions: number; inReview: number; pendingKobo: number; paidKobo: number } | null;
    funnel: { signups: number; verified: number; deliveredOrders: number; completedPlanOrders: number; vendorsRecruited: number } | null;
    payouts: PayoutRow[];
    bankAccount: { bankName: string; accountName: string; maskedNumber: string; verified: boolean } | null;
    tier: {
        name: string;
        description: string | null;
        commissionType: string;
        commissionPercent: number;
        flatAmountKobo: number;
        vendorRecruitmentKobo: number;
    } | null;
    minimumPayoutKobo: number;
    attributionWindowDays: number;
}

export default function Affiliate({
    application,
    links = [],
    stats,
    funnel,
    payouts = [],
    bankAccount,
    tier,
    minimumPayoutKobo,
    attributionWindowDays,
}: Props) {
    const applyForm = useForm({ display_name: application?.displayName ?? '' });
    const linkForm = useForm({ label: '', campaign: '', expires_at: '' });
    const bankForm = useForm({ bank_name: '', bank_code: '', account_number: '', account_name: '' });
    const [copiedId, setCopiedId] = useState<number | null>(null);

    const copyLink = async (link: AffiliateLink) => {
        await navigator.clipboard.writeText(link.url);
        setCopiedId(link.id);
        window.setTimeout(() => setCopiedId(null), 1800);
    };

    const isActive = application?.status === 'approved';

    return (
        <AccountLayout title="Affiliate program">
            <Head title="Affiliate program" />
            <section className="rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-brand-900 p-6 text-white shadow-lg sm:p-8">
                <p className="text-xs font-bold uppercase tracking-[0.18em] text-brand-200">Partner program</p>
                <h1 className="mt-2 text-3xl font-extrabold tracking-tight">Share products. Track real outcomes.</h1>
                <p className="mt-2 max-w-xl text-sm text-slate-300">
                    Clicks and signups are tracked, but commission is only earned on a confirmed delivery. Anyone
                    who arrives through your link stays yours for {attributionWindowDays} days.
                </p>
            </section>

            {!application && (
                <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-3">
                        <ShieldCheck className="h-5 w-5 text-brand-600" />
                        <h2 className="font-bold text-gray-900">Apply to become an affiliate</h2>
                    </div>
                    <p className="mt-2 text-sm text-gray-500">
                        Use a name your audience will recognise. An administrator reviews every application.
                    </p>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyForm.post(route('affiliates.apply'));
                        }}
                        className="mt-5 flex flex-col gap-3 sm:flex-row"
                    >
                        <input
                            value={applyForm.data.display_name}
                            onChange={(event) => applyForm.setData('display_name', event.target.value)}
                            placeholder="Display name"
                            className="min-h-11 flex-1 rounded-lg border border-gray-300 px-3 text-sm shadow-sm"
                        />
                        <button disabled={applyForm.processing} className="rounded-xl bg-brand-600 px-5 py-3 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-60">
                            Submit application
                        </button>
                    </form>
                </section>
            )}

            {application && (
                <>
                    <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p className="text-sm text-gray-500">Application status</p>
                                <p className="mt-1 text-xl font-extrabold capitalize text-gray-900">{application.status}</p>
                            </div>
                            {tier && (
                                <div className="rounded-xl bg-brand-50 px-4 py-3 text-right">
                                    <p className="text-[11px] font-bold uppercase tracking-wide text-brand-700">Your tier</p>
                                    <p className="text-lg font-extrabold text-brand-900">{tier.name}</p>
                                    <p className="text-xs text-brand-700">
                                        {tier.commissionType === 'flat'
                                            ? `${formatNairaFromKobo(tier.flatAmountKobo)} per delivered order`
                                            : `${tier.commissionPercent}% of each delivered order`}
                                        {tier.vendorRecruitmentKobo > 0 &&
                                            ` · ${formatNairaFromKobo(tier.vendorRecruitmentKobo)} per seller recruited`}
                                    </p>
                                </div>
                            )}
                        </div>
                        {application.rejectionReason && <p className="mt-4 text-sm text-red-600">{application.rejectionReason}</p>}
                        {application.suspensionReason && (
                            <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                                <strong className="font-bold">Suspended.</strong> {application.suspensionReason} You keep
                                what you have already earned, but new referrals will not count until this is lifted.
                            </p>
                        )}
                    </section>

                    {stats && (
                        <section className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {(
                                [
                                    ['Clicks', stats.clicks, BarChart3],
                                    ['Signups', stats.signups, ShieldCheck],
                                    ['Awaiting payout', formatNairaFromKobo(stats.pendingKobo), Wallet],
                                    ['Paid out', formatNairaFromKobo(stats.paidKobo), Check],
                                ] as StatRow[]
                            ).map(([label, value, Icon]) => (
                                <div key={label} className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                                    <Icon className="h-5 w-5 text-brand-600" />
                                    <p className="mt-3 text-2xl font-extrabold text-gray-900">{value}</p>
                                    <p className="text-xs font-semibold text-gray-500">{label}</p>
                                </div>
                            ))}
                        </section>
                    )}

                    {funnel && (
                        <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <h2 className="font-bold text-gray-900">Where your referrals get to</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Only delivered orders and recruited sellers earn commission — the earlier steps are here
                                so you can see where people drop off.
                            </p>
                            <div className="mt-4 grid gap-3 sm:grid-cols-5">
                                {[
                                    ['Signed up', funnel.signups],
                                    ['Verified', funnel.verified],
                                    ['Delivered', funnel.deliveredOrders],
                                    ['Plan orders', funnel.completedPlanOrders],
                                    ['Sellers', funnel.vendorsRecruited],
                                ].map(([label, value]) => (
                                    <div key={label as string} className="rounded-xl bg-gray-50 p-3 text-center">
                                        <p className="text-xl font-extrabold text-gray-900">{value}</p>
                                        <p className="text-[11px] font-semibold text-gray-500">{label}</p>
                                    </div>
                                ))}
                            </div>
                            {stats && stats.inReview > 0 && (
                                <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    {stats.inReview} conversion(s) are being reviewed before they can earn.
                                </p>
                            )}
                        </section>
                    )}

                    {isActive && (
                        <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex items-center gap-3">
                                <Link2 className="h-5 w-5 text-brand-600" />
                                <h2 className="font-bold text-gray-900">Your campaign links</h2>
                            </div>
                            <p className="mt-1 text-sm text-gray-500">
                                One link per campaign, so you can tell which post actually worked.
                            </p>

                            <div className="mt-4 space-y-2">
                                {links.map((link) => (
                                    <div key={link.id} className="flex flex-col gap-2 rounded-xl bg-gray-50 p-4 sm:flex-row sm:items-center">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-bold text-gray-900">
                                                {link.label}
                                                {link.campaign && <span className="ml-2 text-xs font-medium text-gray-500">{link.campaign}</span>}
                                                {link.status !== 'active' && (
                                                    <span className="ml-2 rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-bold text-gray-600">Off</span>
                                                )}
                                            </p>
                                            <p className="truncate text-xs text-gray-500">{link.url}</p>
                                            <p className="mt-1 text-[11px] text-gray-400">
                                                {link.clicks} click{link.clicks === 1 ? '' : 's'} · {link.signups} signup
                                                {link.signups === 1 ? '' : 's'}
                                                {link.expiresAt && ` · expires ${link.expiresAt}`}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 gap-2">
                                            <button
                                                onClick={() => copyLink(link)}
                                                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white"
                                            >
                                                {copiedId === link.id ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
                                                {copiedId === link.id ? 'Copied' : 'Copy'}
                                            </button>
                                            {link.status === 'active' && (
                                                <button
                                                    onClick={() => {
                                                        if (!confirm(`Switch off "${link.label}"? Anyone who already signed up through it still counts.`)) return;
                                                        router.delete(route('affiliates.links.destroy', link.id), { preserveScroll: true });
                                                    }}
                                                    className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-600"
                                                >
                                                    Switch off
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    linkForm.post(route('affiliates.links.store'), {
                                        preserveScroll: true,
                                        onSuccess: () => linkForm.reset(),
                                    });
                                }}
                                className="mt-4 grid gap-2 border-t border-gray-100 pt-4 sm:grid-cols-[1fr_1fr_auto_auto]"
                            >
                                <input
                                    value={linkForm.data.label}
                                    onChange={(event) => linkForm.setData('label', event.target.value)}
                                    placeholder="Link name (e.g. Instagram bio)"
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                                />
                                <input
                                    value={linkForm.data.campaign}
                                    onChange={(event) => linkForm.setData('campaign', event.target.value)}
                                    placeholder="Campaign (optional)"
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                                />
                                <input
                                    type="date"
                                    value={linkForm.data.expires_at}
                                    onChange={(event) => linkForm.setData('expires_at', event.target.value)}
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                                />
                                <button disabled={linkForm.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">
                                    Create link
                                </button>
                            </form>
                            {linkForm.errors.label && <p className="mt-1 text-xs text-red-600">{linkForm.errors.label}</p>}
                        </section>
                    )}

                    {isActive && (
                        <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex items-center gap-3">
                                <Landmark className="h-5 w-5 text-brand-600" />
                                <h2 className="font-bold text-gray-900">Where we send your payout</h2>
                            </div>
                            <p className="mt-1 text-sm text-gray-500">
                                Payouts run monthly once you are over {formatNairaFromKobo(minimumPayoutKobo)}. We verify
                                the account name before the first transfer.
                            </p>

                            {bankAccount && (
                                <div className="mt-4 flex flex-wrap items-center gap-3 rounded-xl bg-gray-50 p-4">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-bold text-gray-900">{bankAccount.accountName}</p>
                                        <p className="text-xs text-gray-500">
                                            {bankAccount.bankName} · {bankAccount.maskedNumber}
                                        </p>
                                    </div>
                                    <span
                                        className={`rounded-full px-3 py-1 text-xs font-bold ${
                                            bankAccount.verified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'
                                        }`}
                                    >
                                        {bankAccount.verified ? 'Verified' : 'Awaiting verification'}
                                    </span>
                                </div>
                            )}

                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    bankForm.post(route('affiliates.bank-account.store'), {
                                        preserveScroll: true,
                                        onSuccess: () => bankForm.reset(),
                                    });
                                }}
                                className="mt-4 grid gap-2 border-t border-gray-100 pt-4 sm:grid-cols-2"
                            >
                                <input
                                    value={bankForm.data.bank_name}
                                    onChange={(event) => bankForm.setData('bank_name', event.target.value)}
                                    placeholder="Bank name"
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                                />
                                <input
                                    value={bankForm.data.account_name}
                                    onChange={(event) => bankForm.setData('account_name', event.target.value)}
                                    placeholder="Account name"
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                                />
                                <input
                                    value={bankForm.data.account_number}
                                    onChange={(event) => bankForm.setData('account_number', event.target.value)}
                                    placeholder="Account number"
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                                />
                                <button disabled={bankForm.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">
                                    {bankAccount ? 'Replace account' : 'Save account'}
                                </button>
                            </form>
                            {bankForm.errors.account_number && (
                                <p className="mt-1 text-xs text-red-600">{bankForm.errors.account_number}</p>
                            )}
                            {bankAccount && (
                                <p className="mt-2 text-[11px] text-gray-400">
                                    Replacing the account sends it back for verification before the next payout.
                                </p>
                            )}
                        </section>
                    )}

                    {payouts.length > 0 && (
                        <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <h2 className="font-bold text-gray-900">Payout history</h2>
                            <div className="mt-4 space-y-2">
                                {payouts.map((payout) => (
                                    <div key={payout.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gray-50 px-4 py-3">
                                        <div>
                                            <p className="text-sm font-bold text-gray-900">{formatNairaFromKobo(payout.amountKobo)}</p>
                                            <p className="text-xs text-gray-500">{payout.period}</p>
                                            {(payout.rejectionReason || payout.failureReason) && (
                                                <p className="mt-1 text-xs text-red-600">{payout.rejectionReason ?? payout.failureReason}</p>
                                            )}
                                        </div>
                                        <span className="rounded-full bg-white px-3 py-1 text-xs font-bold capitalize text-gray-600 ring-1 ring-gray-200">
                                            {payout.status}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}
                </>
            )}
        </AccountLayout>
    );
}
