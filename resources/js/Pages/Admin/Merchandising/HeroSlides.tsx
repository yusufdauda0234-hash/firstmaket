import TemplatePicker, { Template } from '@/Components/domain/admin/TemplatePicker';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import { Select } from '@/Components/ui/Select';
import { Textarea } from '@/Components/ui/Textarea';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { HERO_THEMES, heroTheme } from '@/Utils/heroThemes';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Slide {
    id: number;
    eyebrow: string;
    title: string;
    description: string;
    ctaLabel: string;
    ctaTarget: 'auth_gate' | 'catalog' | 'vendor_register';
    theme: string;
    emoji: string;
    offerType: 'from_price' | 'campaign_discount' | 'static';
    offerLabel: string;
    offerValue: string | null;
    isActive: boolean;
    sortOrder: number;
}

interface Props {
    slides: Slide[];
    templates: Template[];
    [key: string]: unknown;
}

const CTA_TARGETS: { value: Slide['ctaTarget']; label: string }[] = [
    { value: 'auth_gate', label: 'Sign in / dashboard' },
    { value: 'catalog', label: 'Catalog' },
    { value: 'vendor_register', label: 'Vendor registration' },
];

const OFFER_TYPES: { value: Slide['offerType']; label: string; hint: string }[] = [
    { value: 'from_price', label: 'Cheapest featured price', hint: 'Shows "Starting from ₦X" using today\'s real cheapest featured product. Hidden if there are none.' },
    { value: 'campaign_discount', label: 'Best live campaign discount', hint: 'Shows the real best "X% OFF" from a currently-running campaign. Hidden while no campaign is live.' },
    { value: 'static', label: 'Fixed text', hint: 'A figure that is always true regardless of catalog state, e.g. "₦0 fees".' },
];

export default function HeroSlides() {
    const { slides = [], templates = [] } = usePage<Props>().props;
    const [editing, setEditing] = useState<Slide | null | undefined>(undefined);

    return (
        <AdminLayout>
            <Head title="Hero slides" />
            <PageHeader
                title="Home page hero slides"
                description="What the top banner on the home page shows. Discount figures are never typed in — they're computed from real catalog and campaign data, and a slide hides itself when it has nothing real to show."
                actions={
                    <div className="flex items-center gap-2">
                        <TemplatePicker
                            templates={templates}
                            action={route('admin.hero-slides.template')}
                            noun="hero slides"
                            empty={slides.length === 0}
                        />
                        <Button onClick={() => setEditing(null)}>Add slide</Button>
                    </div>
                }
            />

            {slides.length === 0 ? (
                <Card className="flex flex-col items-center px-6 py-14 text-center">
                    <p className="text-sm font-medium text-gray-900">No hero slides yet</p>
                    <p className="mt-1 max-w-md text-sm text-gray-500">
                        The home page hero shows nothing until at least one slide exists. Use a template to
                        start, or add one by hand.
                    </p>
                </Card>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    {slides.map((slide) => {
                        const theme = heroTheme(slide.theme);
                        const offerMeta = OFFER_TYPES.find((o) => o.value === slide.offerType);

                        return (
                            <Card key={slide.id} className="flex flex-col gap-3 !p-0 overflow-hidden">
                                <div className={`bg-gradient-to-br p-4 text-white ${theme.bg}`}>
                                    <p className="text-[10px] font-bold uppercase tracking-wide text-brand-yellow">{slide.eyebrow}</p>
                                    <h2 className="mt-1 text-base font-extrabold leading-snug">{slide.title}</h2>
                                    <p className="mt-1 text-xs text-white/80">{slide.description}</p>
                                </div>
                                <div className="flex flex-col gap-2 px-4 pb-4">
                                    <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                                        <span className="rounded-full bg-gray-100 px-2 py-1 font-semibold text-gray-600">{slide.ctaLabel}</span>
                                        <span className="rounded-full bg-gray-100 px-2 py-1 font-semibold text-gray-600">{offerMeta?.label ?? slide.offerType}</span>
                                        {!slide.isActive && (
                                            <span className="rounded-full bg-gray-200 px-2 py-1 font-bold text-gray-500">Off</span>
                                        )}
                                    </div>
                                    <div className="flex gap-2 border-t border-gray-100 pt-3">
                                        <Button variant="secondary" onClick={() => setEditing(slide)}>Edit</Button>
                                        <Button
                                            variant="ghost"
                                            onClick={() => {
                                                if (!confirm(`Delete the "${slide.title}" slide?`)) return;
                                                router.delete(route('admin.hero-slides.destroy', slide.id));
                                            }}
                                        >
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}

            {editing !== undefined && <SlideForm slide={editing} onClose={() => setEditing(undefined)} />}
        </AdminLayout>
    );
}

function SlideForm({ slide, onClose }: { slide: Slide | null; onClose: () => void }) {
    const form = useForm({
        eyebrow: slide?.eyebrow ?? '',
        title: slide?.title ?? '',
        description: slide?.description ?? '',
        cta_label: slide?.ctaLabel ?? '',
        cta_target: slide?.ctaTarget ?? 'auth_gate',
        theme: slide?.theme ?? 'brand',
        emoji: slide?.emoji ?? '🛍️',
        offer_type: slide?.offerType ?? 'from_price',
        offer_label: slide?.offerLabel ?? 'Starting from',
        offer_value: slide?.offerValue ?? '',
        is_active: slide?.isActive ?? true,
        sort_order: slide?.sortOrder ?? 0,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        slide
            ? form.put(route('admin.hero-slides.update', slide.id), options)
            : form.post(route('admin.hero-slides.store'), options);
    };

    return (
        <Modal open onClose={onClose} title={slide ? 'Edit hero slide' : 'Add a hero slide'} size="xl">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Eyebrow</span>
                        <Input value={form.data.eyebrow} onChange={(e) => form.setData('eyebrow', e.target.value)} placeholder="🔥 Super Deals" />
                        <InputError message={form.errors.eyebrow} className="mt-1" />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Emoji fallback</span>
                        <Input value={form.data.emoji} onChange={(e) => form.setData('emoji', e.target.value)} placeholder="🛍️" />
                        <InputError message={form.errors.emoji} className="mt-1" />
                        <span className="mt-1 block text-[11px] text-gray-400">Shown only when the slide has no real product image to display.</span>
                    </label>
                </div>

                <label className="block">
                    <span className="mb-1 block text-xs font-semibold text-gray-600">Title</span>
                    <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                    <InputError message={form.errors.title} className="mt-1" />
                </label>

                <label className="block">
                    <span className="mb-1 block text-xs font-semibold text-gray-600">Description</span>
                    <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    <InputError message={form.errors.description} className="mt-1" />
                </label>

                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Button text</span>
                        <Input value={form.data.cta_label} onChange={(e) => form.setData('cta_label', e.target.value)} placeholder="Grab It Now →" />
                        <InputError message={form.errors.cta_label} className="mt-1" />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Button goes to</span>
                        <Select value={form.data.cta_target} onChange={(e) => form.setData('cta_target', e.target.value as Slide['ctaTarget'])}>
                            {CTA_TARGETS.map((t) => (
                                <option key={t.value} value={t.value}>{t.label}</option>
                            ))}
                        </Select>
                        <InputError message={form.errors.cta_target} className="mt-1" />
                    </label>
                </div>

                <label className="block">
                    <span className="mb-2 block text-xs font-semibold text-gray-600">Color theme</span>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {Object.entries(HERO_THEMES).map(([key, theme]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => form.setData('theme', key)}
                                className={`rounded-lg bg-gradient-to-br p-3 text-left text-[11px] font-bold text-white ring-2 transition ${theme.bg} ${
                                    form.data.theme === key ? 'ring-brand-600' : 'ring-transparent'
                                }`}
                            >
                                {theme.label}
                            </button>
                        ))}
                    </div>
                    <InputError message={form.errors.theme} className="mt-1" />
                </label>

                <div className="rounded-xl border border-gray-100 p-3">
                    <span className="mb-2 block text-xs font-semibold text-gray-600">Offer chip</span>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="block">
                            <span className="mb-1 block text-[11px] text-gray-500">Where the number comes from</span>
                            <Select value={form.data.offer_type} onChange={(e) => form.setData('offer_type', e.target.value as Slide['offerType'])}>
                                {OFFER_TYPES.map((o) => (
                                    <option key={o.value} value={o.value}>{o.label}</option>
                                ))}
                            </Select>
                        </label>
                        <label className="block">
                            <span className="mb-1 block text-[11px] text-gray-500">Label above the number</span>
                            <Input value={form.data.offer_label} onChange={(e) => form.setData('offer_label', e.target.value)} placeholder="Starting from" />
                        </label>
                    </div>
                    <p className="mt-2 text-[11px] leading-relaxed text-gray-400">
                        {OFFER_TYPES.find((o) => o.value === form.data.offer_type)?.hint}
                    </p>
                    {form.data.offer_type === 'static' && (
                        <label className="mt-2 block">
                            <span className="mb-1 block text-[11px] text-gray-500">Fixed text</span>
                            <Input value={form.data.offer_value} onChange={(e) => form.setData('offer_value', e.target.value)} placeholder="₦0 fees" />
                            <InputError message={form.errors.offer_value} className="mt-1" />
                        </label>
                    )}
                </div>

                <div className="grid grid-cols-2 items-end gap-3">
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.checked)}
                            className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                        />
                        Active
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Order (lower shows first)</span>
                        <Input
                            type="number"
                            min={0}
                            value={form.data.sort_order}
                            onChange={(e) => form.setData('sort_order', Number(e.target.value))}
                        />
                    </label>
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                    <Button type="submit" disabled={form.processing}>{slide ? 'Save changes' : 'Add slide'}</Button>
                </div>
            </form>
        </Modal>
    );
}
