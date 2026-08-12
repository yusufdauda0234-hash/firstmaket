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

interface RankRequirement {
    id: number;
    label: string;
    helpText: string | null;
    type: 'document' | 'text' | 'number';
    isRequired: boolean;
}

interface Standing {
    rank: {
        name: string;
        description: string | null;
        commissionPercent: number;
        referralQuota: number;
        linkExpiryDays: number;
        maxActiveLinks: number;
    } | null;
    referralsUsed: number;
    referralsRemaining: number | null;
    canEarn: boolean;
    mustUpgrade: boolean;
    canCreateLink: boolean;
    nextRank: {
        id: number;
        name: string;
        description: string | null;
        commissionPercent: number;
        referralQuota: number;
        linkExpiryDays: number;
        requirements: RankRequirement[];
    } | null;
    pendingRequest: boolean;
    lastRejection: string | null;
}

interface Props {
    standing: Standing | null;
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
    standing,
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

                    {standing?.rank && <RankStanding standing={standing} />}

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

/**
 * Where the partner stands on the ladder, and the way up.
 *
 * The quota meter is the point of this block. A rank allows a fixed number of
 * referrals, and running out is not a failure state — it is the programme
 * saying "you have done the trial, now show us who you are". So it reads as a
 * next step rather than a penalty, and it says plainly that shoppers can
 * still use the link: a partner's first fear on seeing a limit is that the
 * post they shared has gone dead.
 */
function RankStanding({ standing }: { standing: Standing }) {
    const [open, setOpen] = useState(false);
    const rank = standing.rank!;
    const next = standing.nextRank;

    const used = standing.referralsUsed;
    const quota = rank.referralQuota;
    const unlimited = quota <= 0;
    const percent = unlimited ? 0 : Math.min(100, Math.round((used / Math.max(1, quota)) * 100));

    return (
        <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[11px] font-bold uppercase tracking-wide text-gray-400">Your rank</p>
                    <h2 className="mt-0.5 text-xl font-extrabold text-gray-900">{rank.name}</h2>
                    {rank.description && <p className="mt-1 text-sm text-gray-500">{rank.description}</p>}
                </div>
                <span className="rounded-xl bg-brand-50 px-4 py-2 text-center">
                    <span className="block text-lg font-extrabold text-brand-900">{rank.commissionPercent}%</span>
                    <span className="text-[11px] font-semibold text-brand-700">per delivered order</span>
                </span>
            </div>

            <div className="mt-5">
                <div className="flex items-baseline justify-between">
                    <p className="text-sm font-semibold text-gray-700">
                        {unlimited ? 'Unlimited referrals' : `${used} of ${quota} referrals used`}
                    </p>
                    {!unlimited && standing.referralsRemaining !== null && (
                        <p className="text-xs text-gray-500">{standing.referralsRemaining} left on this rank</p>
                    )}
                </div>
                {!unlimited && (
                    <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-gray-100">
                        <div
                            className={`h-full rounded-full transition-all ${standing.canEarn ? 'bg-brand-600' : 'bg-amber-500'}`}
                            style={{ width: `${percent}%` }}
                        />
                    </div>
                )}
                <p className="mt-2 text-xs text-gray-400">
                    {rank.linkExpiryDays > 0
                        ? `Links you create last ${rank.linkExpiryDays} days.`
                        : 'Your links do not expire.'}
                    {rank.maxActiveLinks > 0 && ` Up to ${rank.maxActiveLinks} live at a time.`}
                </p>
            </div>

            {standing.mustUpgrade && (
                <div className="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <strong className="font-bold">You have used this rank&rsquo;s referrals.</strong> Your links
                    still work and anyone can still shop through them — but new referrals will not earn until you
                    move up to {next?.name}.
                </div>
            )}

            {standing.lastRejection && !standing.pendingRequest && (
                <p className="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                    <strong className="font-bold">Your last application was not accepted.</strong>{' '}
                    {standing.lastRejection} You can fix that and apply again.
                </p>
            )}

            {standing.pendingRequest && (
                <p className="mt-4 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600">
                    Your application to move up is with our team. We will let you know.
                </p>
            )}

            {next && !standing.pendingRequest && (
                <div className="mt-5 border-t border-gray-100 pt-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-sm font-bold text-gray-900">Next: {next.name}</p>
                            <p className="text-xs text-gray-500">
                                {next.commissionPercent}% per order ·{' '}
                                {next.referralQuota > 0 ? `${next.referralQuota} referrals` : 'unlimited referrals'} ·{' '}
                                {next.linkExpiryDays > 0 ? `${next.linkExpiryDays}-day links` : 'links never expire'}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setOpen((value) => !value)}
                            className="rounded-full bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700"
                        >
                            {open ? 'Cancel' : `Apply for ${next.name}`}
                        </button>
                    </div>

                    {open && <UpgradeForm next={next} onDone={() => setOpen(false)} />}
                </div>
            )}

            {!next && (
                <p className="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-500">
                    You are on the highest rank. Nothing is capped.
                </p>
            )}
        </section>
    );
}

function UpgradeForm({ next, onDone }: { next: NonNullable<Standing['nextRank']>; onDone: () => void }) {
    const form = useForm<{ answers: Record<string, { value?: string; document?: File | null }> }>({
        answers: {},
    });

    const setAnswer = (id: number, patch: { value?: string; document?: File | null }) =>
        form.setData('answers', {
            ...form.data.answers,
            [id]: { ...(form.data.answers[id] ?? {}), ...patch },
        });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('affiliates.upgrade.request'), {
                    // Documents ride along, so this cannot be a JSON request.
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="mt-4 space-y-4 rounded-xl bg-gray-50 p-4"
        >
            {next.requirements.length === 0 && (
                <p className="text-sm text-gray-600">
                    This rank asks for nothing extra — send the application and our team will review it.
                </p>
            )}

            {next.requirements.map((requirement) => (
                <label key={requirement.id} className="block">
                    <span className="mb-1 block text-xs font-bold text-gray-700">
                        {requirement.label}
                        {!requirement.isRequired && <span className="ml-1 font-medium text-gray-400">(optional)</span>}
                    </span>
                    {requirement.helpText && (
                        <span className="mb-1.5 block text-[11px] text-gray-500">{requirement.helpText}</span>
                    )}

                    {requirement.type === 'document' ? (
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onChange={(event) => setAnswer(requirement.id, { document: event.target.files?.[0] ?? null })}
                            className="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-600 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white"
                        />
                    ) : (
                        <input
                            type={requirement.type === 'number' ? 'number' : 'text'}
                            onChange={(event) => setAnswer(requirement.id, { value: event.target.value })}
                            className="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-sm"
                        />
                    )}
                </label>
            ))}

            {/* The server rejects a missing requirement under its own key,
                which is not one of this form's fields. */}
            {'upgrade' in form.errors && (
                <p className="text-xs text-red-600">{(form.errors as Record<string, string>).upgrade}</p>
            )}

            <button
                type="submit"
                disabled={form.processing}
                className="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-60"
            >
                {form.processing ? 'Sending…' : 'Send application'}
            </button>
            <p className="text-[11px] text-gray-400">
                Documents are stored privately and are only seen by our review team.
            </p>
        </form>
    );
}
