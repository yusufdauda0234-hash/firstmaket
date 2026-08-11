import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head } from '@inertiajs/react';
import { Check, Copy, Gift, Share2, Clock3 } from 'lucide-react';
import { useState } from 'react';

interface ReferralRow {
    name: string;
    status: 'pending' | 'earned';
    rewardAmountKobo: number;
    qualifiedAt: string | null;
}

interface Props {
    code: string;
    link: string;
    rewardAmountKobo: number;
    referrals: ReferralRow[];
}

export default function Referrals({ code, link, rewardAmountKobo, referrals }: Props) {
    const [copied, setCopied] = useState(false);

    async function copyLink() {
        await navigator.clipboard.writeText(link);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1800);
    }

    return (
        <AccountLayout title="Referrals">
            <Head title="Referrals" />

            <section className="rounded-3xl bg-gradient-to-br from-brand-700 via-brand-600 to-teal-700 p-6 text-white shadow-lg sm:p-8">
                <div className="flex flex-wrap items-start justify-between gap-5">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.18em] text-brand-100">Invite and earn</p>
                        <h1 className="mt-2 text-3xl font-extrabold tracking-tight">Share FirstMaket</h1>
                        <p className="mt-2 max-w-lg text-sm text-brand-50">
                            Your friend’s first completed Pay Small Small plan qualifies your referral reward.
                        </p>
                    </div>
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/25">
                        <Gift className="h-7 w-7" />
                    </span>
                </div>

                <div className="mt-7 rounded-2xl bg-white/10 p-4 backdrop-blur sm:flex sm:items-center sm:justify-between sm:gap-4">
                    <div className="min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-wider text-brand-100">Your invite link</p>
                        <p className="mt-1 truncate text-sm font-semibold text-white">{link}</p>
                        <p className="mt-2 text-xs text-brand-100">Code: {code}</p>
                    </div>
                    <button
                        type="button"
                        onClick={copyLink}
                        className="mt-4 inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-brand-700 transition hover:bg-brand-50 sm:mt-0"
                    >
                        {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                        {copied ? 'Copied' : 'Copy link'}
                    </button>
                </div>
            </section>

            <section className="mt-6 grid gap-4 sm:grid-cols-2">
                <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div className="flex items-center gap-3">
                        <Share2 className="h-5 w-5 text-brand-600" />
                        <p className="text-sm font-semibold text-gray-500">Invite reward</p>
                    </div>
                    <p className="mt-3 text-2xl font-extrabold text-gray-900">{formatNairaFromKobo(rewardAmountKobo)}</p>
                    <p className="mt-1 text-xs text-gray-500">Qualified after the first completed plan.</p>
                </div>
                <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div className="flex items-center gap-3">
                        <Gift className="h-5 w-5 text-amber-600" />
                        <p className="text-sm font-semibold text-gray-500">Friends invited</p>
                    </div>
                    <p className="mt-3 text-2xl font-extrabold text-gray-900">{referrals.length}</p>
                    <p className="mt-1 text-xs text-gray-500">Single-level referrals only.</p>
                </div>
            </section>

            <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 className="font-bold text-gray-900">Referral activity</h2>
                {referrals.length === 0 ? (
                    <p className="mt-5 rounded-xl bg-gray-50 p-5 text-center text-sm text-gray-500">No referrals yet.</p>
                ) : (
                    <div className="mt-4 divide-y divide-gray-100">
                        {referrals.map((referral, index) => (
                            <div key={`${referral.name}-${index}`} className="flex items-center gap-3 py-4">
                                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                                    {referral.status === 'earned' ? <Check className="h-4 w-4" /> : <Clock3 className="h-4 w-4" />}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-bold text-gray-900">{referral.name}</p>
                                    <p className="text-xs text-gray-500">
                                        {referral.status === 'earned' ? `Qualified ${referral.qualifiedAt ?? ''}` : 'Pending first completed plan'}
                                    </p>
                                </div>
                                <span className={`text-sm font-bold ${referral.status === 'earned' ? 'text-emerald-600' : 'text-gray-400'}`}>
                                    {referral.status === 'earned' ? formatNairaFromKobo(referral.rewardAmountKobo) : 'Pending'}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </section>
        </AccountLayout>
    );
}
