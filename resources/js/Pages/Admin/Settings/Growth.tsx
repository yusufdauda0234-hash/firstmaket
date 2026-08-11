import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Award, BarChart3, Gift } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Tier {
    id: number;
    name: string;
    minimumCompletedSavingsNaira: number;
    /**
     * Normalised to a list by the controller, but rows seeded before that
     * fix stored a keyed object — typed loosely so the guard below is a real
     * check rather than a cast over a lie.
     */
    benefits: string[] | Record<string, string> | null;
    status: boolean;
    sortOrder: number;
}

/** Accepts either stored shape and always yields the list the form edits. */
const benefitList = (benefits: Tier['benefits']): string[] =>
    Array.isArray(benefits) ? benefits : Object.values(benefits ?? {});

interface Props extends PageProps {
    affiliateCommissionPercent: number;
    affiliateClickDedupeHours: number;
    referralRewardNaira: number;
    tiers: Tier[];
}

export default function GrowthSettings() {
    const { affiliateCommissionPercent, affiliateClickDedupeHours, referralRewardNaira, tiers } = usePage<Props>().props;
    const settings = useForm({
        affiliate_commission_percent: String(affiliateCommissionPercent),
        affiliate_click_dedupe_hours: String(affiliateClickDedupeHours),
        referral_reward_naira: String(referralRewardNaira),
    });

    const submitSettings: FormEventHandler = (event) => {
        event.preventDefault();
        settings.post(route('admin.settings.growth.update'), { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="Growth settings" />
            <PageHeader eyebrow="Phase 2A" title="Growth settings" description="Control affiliate payouts, referral rewards, and reward tiers without changing code. Existing records keep their saved values." />
            <form onSubmit={submitSettings} className="space-y-6">
                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <label className="flex items-center gap-2 text-sm font-bold text-gray-900"><BarChart3 className="h-4 w-4 text-brand-600" /> Affiliate commission</label>
                        <p className="mt-1 text-sm text-gray-500">Applied when a delivered order or first approved referred-vendor product qualifies.</p>
                        <div className="mt-3 flex items-center rounded-lg border border-gray-300 px-4"><input type="number" min="0" max="100" step="0.01" value={settings.data.affiliate_commission_percent} onChange={(event) => settings.setData('affiliate_commission_percent', event.target.value)} className="w-full border-0 bg-transparent px-2 py-3 text-xl font-extrabold focus:outline-none focus:ring-0" /><span className="font-bold text-gray-400">%</span></div>
                    </Card>
                    <Card>
                        <label className="flex items-center gap-2 text-sm font-bold text-gray-900"><BarChart3 className="h-4 w-4 text-brand-600" /> Repeat-click window</label>
                        <p className="mt-1 text-sm text-gray-500">Ignore the same visitor fingerprint on the same link during this period.</p>
                        <div className="mt-3 flex items-center rounded-lg border border-gray-300 px-4"><input type="number" min="1" max="720" step="1" value={settings.data.affiliate_click_dedupe_hours} onChange={(event) => settings.setData('affiliate_click_dedupe_hours', event.target.value)} className="w-full border-0 bg-transparent px-2 py-3 text-xl font-extrabold focus:outline-none focus:ring-0" /><span className="font-bold text-gray-400">hours</span></div>
                    </Card>
                    <Card>
                        <label className="flex items-center gap-2 text-sm font-bold text-gray-900"><Gift className="h-4 w-4 text-brand-600" /> Referral reward</label>
                        <p className="mt-1 text-sm text-gray-500">Default reward copied onto newly created referral records after qualification.</p>
                        <div className="mt-3 flex items-center rounded-lg border border-gray-300 px-4"><span className="font-bold text-gray-400">₦</span><input type="number" min="0" step="0.01" value={settings.data.referral_reward_naira} onChange={(event) => settings.setData('referral_reward_naira', event.target.value)} className="w-full border-0 bg-transparent px-2 py-3 text-xl font-extrabold focus:outline-none focus:ring-0" /></div>
                    </Card>
                </div>
                <Button type="submit" disabled={settings.processing}>{settings.processing ? 'Saving...' : 'Save growth settings'}</Button>
            </form>
            <Card className="mt-6">
                <div className="flex items-center gap-2"><Award className="h-5 w-5 text-brand-600" /><div><h2 className="font-bold text-gray-900">Reward tiers</h2><p className="text-sm text-gray-500">Thresholds and active status are database-backed and editable here.</p></div></div>
                <div className="mt-5 space-y-3">{tiers.map((tier) => <TierRow key={tier.id} tier={tier} />)}</div>
            </Card>
        </AdminLayout>
    );
}

function TierRow({ tier }: { tier: Tier }) {
    const form = useForm({
        name: tier.name,
        minimum_completed_savings_naira: String(tier.minimumCompletedSavingsNaira),
        // Legacy rows stored benefits as a keyed object rather than a list;
        // the server normalises them now, but a stray shape must not be able
        // to throw during render and blank the whole settings page.
        benefits: benefitList(tier.benefits),
        status: tier.status,
        sort_order: tier.sortOrder,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('admin.settings.growth.reward-tiers.update', tier.id), { preserveScroll: true });
    };

    return <form onSubmit={submit} className="grid gap-3 border-t border-gray-100 pt-4 md:grid-cols-[1.2fr_1fr_1.4fr_110px_110px_auto] md:items-end"><label className="text-xs font-bold text-gray-600">Name<input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className="border mt-1 w-full rounded-lg border-gray-300 text-sm px-3 py-2 shadow-sm" /></label><label className="text-xs font-bold text-gray-600">Minimum completed savings<input type="number" min="0" step="0.01" value={form.data.minimum_completed_savings_naira} onChange={(event) => form.setData('minimum_completed_savings_naira', event.target.value)} className="border mt-1 w-full rounded-lg border-gray-300 text-sm px-3 py-2 shadow-sm" /></label><label className="text-xs font-bold text-gray-600">Benefits, one per line<textarea value={form.data.benefits.join('\n')} onChange={(event) => form.setData('benefits', event.target.value.split('\n').map((benefit) => benefit.trim()).filter(Boolean))} rows={2} className="border mt-1 w-full rounded-lg border-gray-300 text-sm px-3 py-2 shadow-sm" /></label><label className="text-xs font-bold text-gray-600">Order<input type="number" min="0" value={form.data.sort_order} onChange={(event) => form.setData('sort_order', Number(event.target.value))} className="border mt-1 w-full rounded-lg border-gray-300 text-sm px-3 py-2 shadow-sm" /></label><label className="flex items-center gap-2 pb-2 text-sm font-semibold text-gray-700"><input type="checkbox" checked={form.data.status} onChange={(event) => form.setData('status', event.target.checked)} /> Active</label><Button type="submit" disabled={form.processing}>Save</Button></form>;
}
