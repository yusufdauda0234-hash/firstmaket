import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head } from '@inertiajs/react';
import { Award, Check, LockKeyhole, Sparkles } from 'lucide-react';

interface Tier {
    name: string;
    minimumCompletedSavings: number;
    benefits: { badge?: string };
}

interface Props {
    current: Tier & { awardedAt: string | null };
    lifetimeCompletedSavingsKobo: number;
    nextTier: { name: string; minimumCompletedSavings: number } | null;
    tiers: Tier[];
}

export default function Rewards({ current, lifetimeCompletedSavingsKobo, nextTier, tiers }: Props) {
    const progress = nextTier
        ? Math.min(
              100,
              Math.round(
                  ((lifetimeCompletedSavingsKobo - current.minimumCompletedSavings) * 100) /
                      (nextTier.minimumCompletedSavings - current.minimumCompletedSavings),
              ),
          )
        : 100;

    return (
        <AccountLayout title="Rewards & badges">
            <Head title="Rewards & badges" />

            <section className="overflow-hidden rounded-3xl bg-gradient-to-br from-amber-500 via-orange-500 to-brand-700 p-6 text-white shadow-lg sm:p-8">
                <div className="flex flex-wrap items-start justify-between gap-6">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.18em] text-amber-100">Your badge</p>
                        <h1 className="mt-2 text-3xl font-extrabold tracking-tight">{current.name}</h1>
                        <p className="mt-2 max-w-md text-sm text-orange-50">
                            {current.benefits.badge ?? 'Keep completing Pay Small Small plans to grow your badge.'}
                        </p>
                    </div>
                    <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/20 ring-1 ring-white/30">
                        <Award className="h-9 w-9" />
                    </span>
                </div>

                <div className="mt-8 grid gap-5 sm:grid-cols-2">
                    <div>
                        <div className="flex items-center justify-between text-xs font-semibold text-orange-50">
                            <span>Completed savings</span>
                            <span>{formatNairaFromKobo(lifetimeCompletedSavingsKobo)}</span>
                        </div>
                        <div className="mt-2 h-2 overflow-hidden rounded-full bg-black/20">
                            <span className="block h-full rounded-full bg-white transition-all" style={{ width: `${progress}%` }} />
                        </div>
                    </div>
                    <div className="text-sm text-orange-50 sm:text-right">
                        {nextTier ? (
                            <>
                                <span className="font-bold text-white">{nextTier.name}</span> starts at{' '}
                                {formatNairaFromKobo(nextTier.minimumCompletedSavings)}
                            </>
                        ) : (
                            <span className="font-bold text-white">Top tier reached</span>
                        )}
                    </div>
                </div>
            </section>

            <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-amber-600">
                        <Sparkles className="h-5 w-5" />
                    </span>
                    <div>
                        <h2 className="font-bold text-gray-900">Badge path</h2>
                        <p className="text-sm text-gray-500">Every completed plan counts toward your lifetime total.</p>
                    </div>
                </div>

                <div className="mt-6 grid gap-3 sm:grid-cols-2">
                    {tiers.map((tier) => {
                        const earned = tier.minimumCompletedSavings <= lifetimeCompletedSavingsKobo;
                        const active = tier.name === current.name;
                        return (
                            <div
                                key={tier.name}
                                className={`flex items-center gap-3 rounded-xl border p-4 ${
                                    active ? 'border-amber-300 bg-amber-50' : 'border-gray-200'
                                }`}
                            >
                                <span
                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${
                                        earned ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-400'
                                    }`}
                                >
                                    {earned ? <Check className="h-5 w-5" /> : <LockKeyhole className="h-4 w-4" />}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="font-bold text-gray-900">{tier.name}</p>
                                    <p className="text-xs text-gray-500">
                                        From {formatNairaFromKobo(tier.minimumCompletedSavings)} completed
                                    </p>
                                </div>
                                {active && <span className="text-xs font-bold text-amber-700">Current</span>}
                            </div>
                        );
                    })}
                </div>
            </section>
        </AccountLayout>
    );
}
