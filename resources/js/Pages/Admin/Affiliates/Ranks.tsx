import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FileText, Plus, Trash2, TrendingUp } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Requirement {
    id: number;
    label: string;
    helpText: string | null;
    type: string;
    isRequired: boolean;
}

interface Rank {
    id: number;
    name: string;
    description: string | null;
    commissionPercent: number;
    vendorRecruitmentKobo: number;
    referralQuota: number;
    linkExpiryDays: number;
    maxActiveLinks: number;
    requiresApproval: boolean;
    minDeliveredConversions: number;
    isDefault: boolean;
    isActive: boolean;
    sortOrder: number;
    partnerCount: number;
    requirements: Requirement[];
}

interface UpgradeRequest {
    uuid: string;
    affiliate: string | null;
    from: string | null;
    to: string | null;
    submittedAt: string | null;
    answers: { label: string | null; type: string | null; value: string | null; documentUuid: string | null }[];
}

interface Props {
    ranks: Rank[];
    upgradeRequests: UpgradeRequest[];
    [key: string]: unknown;
}

const ask = (message: string) => {
    const answer = window.prompt(message);

    return answer && answer.trim() !== '' ? answer.trim() : null;
};

/** Zero means unlimited on every ceiling here, so it needs saying out loud. */
const limit = (value: number, unit: string) => (value > 0 ? `${value} ${unit}` : 'Unlimited');

export default function Ranks() {
    const { ranks = [], upgradeRequests = [] } = usePage<Props>().props;
    const [editing, setEditing] = useState<Rank | null | undefined>(undefined);

    return (
        <AdminLayout>
            <Head title="Affiliate ranks" />
            <PageHeader
                title="Affiliate ranks"
                description="Each rank decides how many referrals a partner may earn on, how long their links last, and what they are paid. Running out of referrals pauses earning — it never breaks a link a shopper has already been given."
                actions={<Button onClick={() => setEditing(null)}>Add rank</Button>}
            />

            {upgradeRequests.length > 0 && (
                <Card className="mb-6 overflow-hidden !p-0">
                    <div className="flex items-center gap-2 border-b border-gray-100 px-5 py-3.5">
                        <TrendingUp className="h-4 w-4 text-brand-600" />
                        <h2 className="font-bold text-gray-900">
                            Partners asking to move up ({upgradeRequests.length})
                        </h2>
                    </div>
                    <div className="divide-y divide-gray-50">
                        {upgradeRequests.map((request) => (
                            <div key={request.uuid} className="px-5 py-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-bold text-gray-900">
                                            {request.affiliate}
                                            <span className="ml-2 font-medium text-gray-500">
                                                {request.from ?? '—'} → {request.to}
                                            </span>
                                        </p>
                                        <p className="text-xs text-gray-400">{request.submittedAt}</p>

                                        <dl className="mt-3 space-y-1.5">
                                            {request.answers.map((answer, index) => (
                                                <div key={index} className="flex flex-wrap gap-2 text-xs">
                                                    <dt className="font-semibold text-gray-600">{answer.label}:</dt>
                                                    <dd className="text-gray-700">
                                                        {answer.documentUuid ? (
                                                            <a
                                                                href={route('admin.documents.download', answer.documentUuid)}
                                                                className="inline-flex items-center gap-1 font-semibold text-brand-600 hover:underline"
                                                            >
                                                                <FileText className="h-3 w-3" /> View document
                                                            </a>
                                                        ) : (
                                                            answer.value || <span className="italic text-gray-400">not provided</span>
                                                        )}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </div>

                                    <div className="flex shrink-0 gap-2">
                                        <button
                                            onClick={() =>
                                                router.post(route('admin.affiliates.upgrades.approve', request.uuid), {}, { preserveScroll: true })
                                            }
                                            className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white"
                                        >
                                            Approve
                                        </button>
                                        <button
                                            onClick={() => {
                                                const reason = ask('Why is this application being rejected? The partner will see this.');
                                                if (reason) {
                                                    router.post(
                                                        route('admin.affiliates.upgrades.reject', request.uuid),
                                                        { reason },
                                                        { preserveScroll: true },
                                                    );
                                                }
                                            }}
                                            className="rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white"
                                        >
                                            Reject
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </Card>
            )}

            <div className="space-y-4">
                {ranks.map((rank) => (
                    <Card key={rank.id} className={rank.isActive ? '' : 'opacity-60'}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="flex items-center gap-2 font-bold text-gray-900">
                                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 font-mono text-xs text-gray-600">
                                        {rank.sortOrder}
                                    </span>
                                    {rank.name}
                                    {rank.isDefault && (
                                        <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-bold text-brand-700">
                                            Starting rank
                                        </span>
                                    )}
                                    {!rank.isActive && (
                                        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-bold text-gray-600">
                                            Retired
                                        </span>
                                    )}
                                </h2>
                                {rank.description && <p className="mt-1 text-sm text-gray-500">{rank.description}</p>}
                            </div>
                            <p className="text-xs text-gray-500">
                                {rank.partnerCount} partner{rank.partnerCount === 1 ? '' : 's'}
                            </p>
                        </div>

                        <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {[
                                ['Rate', `${rank.commissionPercent}% per order`],
                                ['Referrals allowed', limit(rank.referralQuota, '')],
                                ['Link lifetime', rank.linkExpiryDays > 0 ? `${rank.linkExpiryDays} days` : 'Never expires'],
                                ['Live links', limit(rank.maxActiveLinks, '')],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded-lg bg-gray-50 px-3 py-2">
                                    <dt className="text-[11px] font-semibold text-gray-500">{label}</dt>
                                    <dd className="text-sm font-bold text-gray-900">{value}</dd>
                                </div>
                            ))}
                        </dl>

                        <p className="mt-3 text-xs text-gray-500">
                            Seller recruitment pays {formatNairaFromKobo(rank.vendorRecruitmentKobo)} ·{' '}
                            {rank.requiresApproval ? 'entry reviewed by staff' : 'entered automatically on approval'}
                        </p>

                        <div className="mt-4 rounded-xl border border-gray-100 p-3">
                            <p className="text-[11px] font-bold uppercase tracking-wide text-gray-500">
                                Asked for before entering this rank
                            </p>
                            {rank.requirements.length === 0 ? (
                                <p className="mt-2 text-xs text-gray-400">Nothing — partners enter without submitting anything.</p>
                            ) : (
                                <ul className="mt-2 space-y-1.5">
                                    {rank.requirements.map((requirement) => (
                                        <li key={requirement.id} className="flex items-center justify-between gap-3 text-sm">
                                            <span className="text-gray-700">
                                                {requirement.label}
                                                <span className="ml-2 text-[11px] text-gray-400">
                                                    {requirement.type}
                                                    {!requirement.isRequired && ' · optional'}
                                                </span>
                                            </span>
                                            <button
                                                onClick={() => {
                                                    if (!confirm(`Remove “${requirement.label}” from ${rank.name}?`)) return;
                                                    router.delete(route('admin.affiliates.requirements.destroy', requirement.id), {
                                                        preserveScroll: true,
                                                    });
                                                }}
                                                className="rounded p-1 text-gray-300 hover:text-red-500"
                                                aria-label={`Remove ${requirement.label}`}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <RequirementForm rankId={rank.id} />
                        </div>

                        <div className="mt-4 flex gap-2 border-t border-gray-100 pt-3">
                            <Button variant="secondary" onClick={() => setEditing(rank)}>
                                Edit rank
                            </Button>
                            {!rank.isDefault && rank.isActive && (
                                <Button
                                    variant="ghost"
                                    onClick={() => {
                                        if (!confirm(`Retire “${rank.name}”? Partners on it keep their rate until they are moved.`)) return;
                                        router.delete(route('admin.affiliates.ranks.destroy', rank.id), { preserveScroll: true });
                                    }}
                                >
                                    Retire
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>

            {editing !== undefined && <RankForm rank={editing} onClose={() => setEditing(undefined)} />}
        </AdminLayout>
    );
}

function RequirementForm({ rankId }: { rankId: number }) {
    const form = useForm({ label: '', help_text: '', type: 'document', is_required: true, sort_order: 0 });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.affiliates.ranks.requirements.store', rankId), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="mt-3 grid gap-2 border-t border-gray-100 pt-3 sm:grid-cols-[1fr_1fr_8rem_auto]">
            <Input
                value={form.data.label}
                onChange={(event) => form.setData('label', event.target.value)}
                placeholder="What to ask for"
            />
            <Input
                value={form.data.help_text}
                onChange={(event) => form.setData('help_text', event.target.value)}
                placeholder="Hint for the partner (optional)"
            />
            <Select value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                <option value="document">Document</option>
                <option value="text">Text</option>
                <option value="number">Number</option>
            </Select>
            <Button type="submit" disabled={form.processing || form.data.label.trim() === ''}>
                <Plus className="h-4 w-4" />
            </Button>
        </form>
    );
}

function RankForm({ rank, onClose }: { rank: Rank | null; onClose: () => void }) {
    const form = useForm({
        name: rank?.name ?? '',
        description: rank?.description ?? '',
        commission_percent: rank?.commissionPercent ?? 5,
        vendor_recruitment_kobo: rank?.vendorRecruitmentKobo ?? 0,
        referral_quota: rank?.referralQuota ?? 3,
        link_expiry_days: rank?.linkExpiryDays ?? 30,
        max_active_links: rank?.maxActiveLinks ?? 1,
        requires_approval: rank?.requiresApproval ?? true,
        min_delivered_conversions: rank?.minDeliveredConversions ?? 0,
        is_active: rank?.isActive ?? true,
        sort_order: rank?.sortOrder ?? 1,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        rank
            ? form.put(route('admin.affiliates.ranks.update', rank.id), options)
            : form.post(route('admin.affiliates.ranks.store'), options);
    };

    const numberField = (
        key: 'referral_quota' | 'link_expiry_days' | 'max_active_links' | 'min_delivered_conversions' | 'sort_order' | 'vendor_recruitment_kobo',
        label: string,
        hint: string,
    ) => (
        <label className="block">
            <span className="mb-1 block text-xs font-bold text-gray-700">{label}</span>
            <Input
                type="number"
                min={0}
                value={form.data[key]}
                onChange={(event) => form.setData(key, Number(event.target.value))}
            />
            <span className="mt-1 block text-[11px] text-gray-400">{hint}</span>
        </label>
    );

    return (
        <Modal open onClose={onClose} title={rank ? `Edit ${rank.name}` : 'Add a rank'} size="xl">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-gray-700">Name</span>
                        <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        <InputError message={form.errors.name} className="mt-1" />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-gray-700">Commission %</span>
                        <Input
                            type="number"
                            step="0.01"
                            min={0}
                            max={100}
                            value={form.data.commission_percent}
                            onChange={(event) => form.setData('commission_percent', Number(event.target.value))}
                        />
                        <InputError message={form.errors.commission_percent} className="mt-1" />
                    </label>
                </div>

                <label className="block">
                    <span className="mb-1 block text-xs font-bold text-gray-700">Description</span>
                    <Input
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        placeholder="What this rank is for, in one line"
                    />
                </label>

                <div className="grid gap-3 sm:grid-cols-3">
                    {numberField('referral_quota', 'Referrals allowed', '0 = unlimited. Earning pauses at this number until they upgrade.')}
                    {numberField('link_expiry_days', 'Link lifetime (days)', '0 = links never expire.')}
                    {numberField('max_active_links', 'Live links at once', '0 = unlimited.')}
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    {numberField('vendor_recruitment_kobo', 'Seller recruitment (kobo)', 'Paid per recruited seller.')}
                    {numberField('min_delivered_conversions', 'Deliveries to qualify', 'Before they may apply for this rank.')}
                    {numberField('sort_order', 'Position on the ladder', 'Lower is earlier. 1 is the starting rank.')}
                </div>

                <div className="flex flex-wrap gap-4">
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.requires_approval}
                            onChange={(event) => form.setData('requires_approval', event.target.checked)}
                            className="h-4 w-4 rounded border-gray-300 text-brand-600"
                        />
                        Entry reviewed by staff
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                            className="h-4 w-4 rounded border-gray-300 text-brand-600"
                        />
                        Active
                    </label>
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {rank ? 'Save rank' : 'Add rank'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
