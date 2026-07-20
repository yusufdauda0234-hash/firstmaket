import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { cn } from '@/Utils/cn';
import { Head, useForm, usePage } from '@inertiajs/react';
import { BadgeCheck, CheckCircle2, Gift, Layers, Sparkles, Star, Wallet } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props extends PageProps {
    settings: {
        postingMode: 'free' | 'paid';
        basicFeeNaira: number;
        premiumFeeNaira: number;
        featuredFeeNaira: number;
        updatedAt: string | null;
    };
}

const TIER_FIELDS = [
    {
        key: 'basic_fee_naira',
        label: 'Basic tier',
        blurb: 'Standard listing placement.',
        icon: Layers,
        accent: 'bg-gray-100 text-gray-600',
    },
    {
        key: 'premium_fee_naira',
        label: 'Premium tier',
        blurb: 'Higher placement in category pages.',
        icon: Star,
        accent: 'bg-amber-100 text-amber-600',
    },
    {
        key: 'featured_fee_naira',
        label: 'Featured tier',
        blurb: 'Eligible for the home page featured strip.',
        icon: Sparkles,
        accent: 'bg-violet-100 text-violet-600',
    },
] as const;

export default function FeeSettings() {
    const { settings } = usePage<Props>().props;

    const form = useForm({
        posting_mode: settings.postingMode,
        basic_fee_naira: settings.basicFeeNaira,
        premium_fee_naira: settings.premiumFeeNaira,
        featured_fee_naira: settings.featuredFeeNaira,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('admin.settings.fees.update'));
    };

    const isFree = form.data.posting_mode === 'free';

    const modes = [
        {
            value: 'free',
            title: 'Free',
            blurb: 'All listings post free of charge',
            icon: Gift,
        },
        {
            value: 'paid',
            title: 'Paid',
            blurb: 'Vendors pay per tier when submitting',
            icon: Wallet,
        },
    ] as const;

    return (
        <AdminLayout>
            <Head title="Vendor fee settings" />

            <PageHeader
                eyebrow="System"
                title="Vendor fee settings"
                description="Posting fees for new product listings. Changes apply only to listings submitted after saving — fees already recorded keep their original amount."
            />

            <Reveal>
                <form onSubmit={submit} className="max-w-2xl space-y-5">
                    <Card>
                        <h2 className="text-lg font-bold text-gray-900">Posting mode</h2>
                        <p className="mb-4 mt-1 text-sm text-gray-500">
                            In Free mode every listing posts at no charge and tier fees are ignored.
                        </p>
                        <div className="flex flex-col gap-3 sm:flex-row">
                            {modes.map((mode) => {
                                const selected = form.data.posting_mode === mode.value;
                                return (
                                    <label
                                        key={mode.value}
                                        className={cn(
                                            'group relative flex flex-1 cursor-pointer items-center gap-3 rounded-2xl border-2 p-4 transition-all duration-200',
                                            selected
                                                ? 'border-brand-600 bg-brand-50/70 shadow-sm shadow-brand-600/10'
                                                : 'border-gray-200 bg-white hover:border-brand-300 hover:bg-brand-50/40',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="posting_mode"
                                            value={mode.value}
                                            checked={selected}
                                            onChange={() => form.setData('posting_mode', mode.value)}
                                            className="sr-only"
                                        />
                                        <span
                                            className={cn(
                                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-colors',
                                                selected
                                                    ? 'bg-brand-600 text-white'
                                                    : 'bg-gray-100 text-gray-500 group-hover:bg-brand-100 group-hover:text-brand-600',
                                            )}
                                        >
                                            <mode.icon className="h-5 w-5" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-sm font-bold text-gray-900">{mode.title}</span>
                                            <span className="block text-xs text-gray-500">{mode.blurb}</span>
                                        </span>
                                        {selected && (
                                            <CheckCircle2 className="absolute right-3 top-3 h-5 w-5 text-brand-600" />
                                        )}
                                    </label>
                                );
                            })}
                        </div>
                        <InputError message={form.errors.posting_mode} />
                    </Card>

                    <Card className={cn('transition-opacity duration-200', isFree && 'opacity-60')}>
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <h2 className="text-lg font-bold text-gray-900">Tier fees</h2>
                            {isFree && (
                                <span className="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                    Ignored in free mode
                                </span>
                            )}
                        </div>
                        <div className="space-y-4">
                            {TIER_FIELDS.map((field) => (
                                <div
                                    key={field.key}
                                    className="flex flex-col gap-3 rounded-2xl border border-gray-100 bg-gray-50/60 p-4 sm:flex-row sm:items-center"
                                >
                                    <div className="flex flex-1 items-center gap-3">
                                        <span
                                            className={cn(
                                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                                                field.accent,
                                            )}
                                        >
                                            <field.icon className="h-5 w-5" />
                                        </span>
                                        <div>
                                            <Label htmlFor={field.key} className="mb-0 font-semibold text-gray-900">
                                                {field.label}
                                            </Label>
                                            <p className="text-xs text-gray-500">{field.blurb}</p>
                                        </div>
                                    </div>
                                    <div className="w-full sm:w-44">
                                        <div className="flex items-center rounded-xl border border-gray-300 bg-white shadow-sm transition focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20">
                                            <span className="pl-3 text-sm font-bold text-gray-400">₦</span>
                                            <input
                                                id={field.key}
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                value={form.data[field.key]}
                                                onChange={(e) => form.setData(field.key, Number(e.target.value))}
                                                disabled={isFree}
                                                className="w-full rounded-xl border-0 bg-transparent px-2 py-2.5 text-right text-sm font-semibold tabular-nums text-gray-900 focus:outline-none focus:ring-0 disabled:cursor-not-allowed"
                                            />
                                        </div>
                                        <InputError message={form.errors[field.key]} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>

                    <div className="flex flex-wrap items-center gap-4">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save fee settings'}
                        </Button>
                        {form.recentlySuccessful && (
                            <span className="flex items-center gap-1.5 text-sm font-medium text-green-600">
                                <BadgeCheck className="h-4 w-4" /> Saved.
                            </span>
                        )}
                        {settings.updatedAt && (
                            <span className="text-xs text-gray-400">Last updated {settings.updatedAt}</span>
                        )}
                    </div>
                </form>
            </Reveal>
        </AdminLayout>
    );
}
