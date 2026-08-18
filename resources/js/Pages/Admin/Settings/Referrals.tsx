import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Gift } from 'lucide-react';

interface Props {
    rewardAmountKobo: number;
    rewardAmountNaira: number;
    [key: string]: unknown;
}

export default function ReferralSettings() {
    const { rewardAmountNaira } = usePage<Props>().props;
    const form = useForm({ reward_amount_naira: rewardAmountNaira });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.settings.referrals.store'), {
            preserveScroll: true,
        });
    };

    const naira = new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        maximumFractionDigits: 0,
    });

    return (
        <AdminLayout>
            <Head title="Referral settings" />

            <PageHeader
                eyebrow="Growth & acquisition"
                title="Referral rewards"
                description="Configure how much each successful referral earns the referrer when their referred friend completes a Pay Small Small plan."
            />

            <Card className="max-w-2xl">
                <div className="mb-6 flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <Gift className="h-6 w-6" />
                    </span>
                    <div>
                        <h2 className="font-bold text-gray-900">Reward per successful referral</h2>
                        <p className="text-xs text-gray-500">
                            Paid from FirstMaket's account to the referrer's Open Savings
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label htmlFor="reward_amount" className="mb-1.5 block text-xs font-bold text-gray-700">
                            Reward amount (in Naira)
                        </label>
                        <Input
                            id="reward_amount"
                            type="number"
                            min="0"
                            step="100"
                            value={form.data.reward_amount_naira}
                            onChange={(e) => form.setData('reward_amount_naira', parseFloat(e.target.value))}
                        />
                        <InputError message={form.errors.reward_amount_naira} className="mt-1" />
                        <p className="mt-2 text-sm text-gray-600">
                            Currently set to <strong>{naira.format(form.data.reward_amount_naira)}</strong> per referral
                        </p>
                    </div>

                    <div className="rounded-xl bg-blue-50 px-4 py-3">
                        <p className="text-sm leading-relaxed text-blue-900">
                            <strong>How it works:</strong> When a referrer sends their unique code to a friend and that friend completes their first Pay Small Small plan, the referrer receives this amount in their Open Savings account automatically.
                        </p>
                    </div>

                    <div className="flex gap-3 pt-4">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                        >
                            {form.processing ? 'Saving…' : 'Save reward amount'}
                        </button>
                    </div>
                </form>
            </Card>

            <Card className="mt-4 bg-gray-50">
                <h3 className="text-sm font-bold text-gray-900">Next steps</h3>
                <ul className="mt-3 space-y-2 text-sm text-gray-600">
                    <li className="flex gap-3">
                        <span className="text-brand-600">•</span>
                        <span>Customers can find their referral link in Account → Referrals</span>
                    </li>
                    <li className="flex gap-3">
                        <span className="text-brand-600">•</span>
                        <span>Rewards are automatically credited after the referred customer completes their first plan payment</span>
                    </li>
                    <li className="flex gap-3">
                        <span className="text-brand-600">•</span>
                        <span>Rewards appear in the referrer's Open Savings and are available to withdraw or spend</span>
                    </li>
                </ul>
            </Card>
        </AdminLayout>
    );
}
