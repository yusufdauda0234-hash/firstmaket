import TemplatePicker, { Template } from '@/Components/domain/admin/TemplatePicker';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import { MoneyInput } from '@/Components/ui/MoneyInput';
import PageHeader from '@/Components/ui/PageHeader';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Pencil, Percent, Plus, Power, Tag, Truck } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type PromoType = 'percent' | 'fixed' | 'free_delivery';
type Status = 'live' | 'off' | 'scheduled' | 'expired' | 'claimed';

interface PromoCode {
    uuid: string;
    code: string;
    description: string | null;
    type: PromoType;
    label: string;
    percentOff: number | null;
    amountOffNaira: number | null;
    maxDiscountNaira: number | null;
    minOrderNaira: number;
    startsAt: string | null;
    endsAt: string | null;
    maxRedemptions: number | null;
    maxPerCustomer: number;
    firstOrderOnly: boolean;
    isActive: boolean;
    redemptionCount: number;
    spendNaira: number;
    status: Status;
}

interface Props {
    /** Ready-made settings an admin can apply in one click. */
    templates: Template[];
    codes: PromoCode[];
    [key: string]: unknown;
}

const naira = new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 0,
});

const TYPE_META: Record<PromoType, { label: string; icon: typeof Tag; tone: string }> = {
    percent: { label: 'Percentage', icon: Percent, tone: 'bg-violet-50 text-violet-700' },
    fixed: { label: 'Fixed amount', icon: Tag, tone: 'bg-sky-50 text-sky-700' },
    free_delivery: { label: 'Free delivery', icon: Truck, tone: 'bg-emerald-50 text-emerald-700' },
};

/**
 * Why a code is or is not working right now. "Off" is a choice; the other
 * three are consequences, and an admin looking at a code a customer says is
 * broken needs to be told which.
 */
const STATUS_META: Record<Status, { label: string; tone: string }> = {
    live: { label: 'Live', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    scheduled: { label: 'Not started', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
    expired: { label: 'Expired', tone: 'bg-gray-100 text-gray-500 ring-gray-200' },
    claimed: { label: 'Fully claimed', tone: 'bg-gray-100 text-gray-500 ring-gray-200' },
    off: { label: 'Switched off', tone: 'bg-gray-100 text-gray-500 ring-gray-200' },
};

/**
 * Promo codes.
 *
 * Every code is platform-funded: the discount comes out of FirstMaket's
 * commission and the vendor is paid as though the customer had paid full
 * price. The page says so once, at the top, because it is the fact that
 * explains every limit below it — including why a code can never take off
 * more than the commission on the basket.
 */
export default function PromoCodes() {
    const { codes = [], templates = [] } = usePage<Props>().props;
    const [editing, setEditing] = useState<PromoCode | null | undefined>(undefined);

    const live = codes.filter((code) => code.status === 'live').length;
    const spend = codes.reduce((total, code) => total + code.spendNaira, 0);

    return (
        <AdminLayout>
            <Head title="Promo codes" />

            <PageHeader
                title="Promo codes"
                description="Discounts come out of FirstMaket's commission — vendors are always paid in full."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <TemplatePicker
                            templates={templates}
                            action={route('admin.settings.promo-codes.template')}
                            noun="promo codes"
                            empty={codes.length === 0}
                        />
                        <button
                            type="button"
                            onClick={() => setEditing(null)}
                            className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700"
                        >
                            <Plus className="h-4 w-4" /> New code
                        </button>
                    </div>
                }
            />

            <div className="grid gap-3 sm:grid-cols-3">
                <Card className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Live now</p>
                    <p className="mt-1 text-2xl font-extrabold tabular-nums text-gray-900">{live}</p>
                </Card>
                <Card className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Codes</p>
                    <p className="mt-1 text-2xl font-extrabold tabular-nums text-gray-900">{codes.length}</p>
                </Card>
                <Card className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Given away
                    </p>
                    <p className="mt-1 text-2xl font-extrabold tabular-nums text-gray-900">
                        {naira.format(spend)}
                    </p>
                </Card>
            </div>

            <Card className="mt-4 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[52rem] text-left text-sm">
                        <thead className="border-b border-gray-100 bg-gray-50/60 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3 font-semibold">Code</th>
                                <th className="px-4 py-3 font-semibold">Discount</th>
                                <th className="px-4 py-3 font-semibold">Conditions</th>
                                <th className="px-4 py-3 text-right font-semibold">Used</th>
                                <th className="px-4 py-3 text-right font-semibold">Cost</th>
                                <th className="px-4 py-3 font-semibold">Status</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {codes.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-gray-400">
                                        No promo codes yet.
                                    </td>
                                </tr>
                            )}

                            {codes.map((code) => {
                                const meta = TYPE_META[code.type];
                                const Icon = meta.icon;
                                const status = STATUS_META[code.status];

                                return (
                                    <tr key={code.uuid} className="align-top hover:bg-gray-50/60">
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-sm font-bold tracking-wide text-gray-900">
                                                {code.code}
                                            </span>
                                            {code.description && (
                                                <p className="mt-0.5 text-xs text-gray-400">
                                                    {code.description}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-bold ${meta.tone}`}
                                            >
                                                <Icon className="h-3.5 w-3.5" /> {code.label}
                                            </span>
                                            {code.maxDiscountNaira !== null && (
                                                <p className="mt-1 text-xs text-gray-400">
                                                    up to {naira.format(code.maxDiscountNaira)}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs leading-relaxed text-gray-500">
                                            {conditionLines(code).map((line) => (
                                                <div key={line}>{line}</div>
                                            ))}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-gray-700">
                                            {code.redemptionCount}
                                            {code.maxRedemptions !== null && (
                                                <span className="text-gray-300">
                                                    {' '}
                                                    / {code.maxRedemptions}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-gray-700">
                                            {naira.format(code.spendNaira)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-block rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ${status.tone}`}
                                            >
                                                {status.label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => setEditing(code)}
                                                    className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                                                    aria-label={`Edit ${code.code}`}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                {code.isActive && (
                                                    <button
                                                        type="button"
                                                        onClick={() => switchOff(code)}
                                                        className="rounded-lg p-1.5 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                                                        aria-label={`Switch off ${code.code}`}
                                                    >
                                                        <Power className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </Card>

            {editing !== undefined && (
                <CodeForm code={editing} onClose={() => setEditing(undefined)} />
            )}
        </AdminLayout>
    );
}

/** The limits on a code, one short line each. */
function conditionLines(code: PromoCode): string[] {
    const lines: string[] = [];

    if (code.minOrderNaira > 0) lines.push(`Orders over ${naira.format(code.minOrderNaira)}`);
    if (code.firstOrderOnly) lines.push('First order only');
    if (code.maxPerCustomer > 1) lines.push(`${code.maxPerCustomer} per customer`);
    if (code.startsAt || code.endsAt) {
        lines.push(`${code.startsAt ?? 'Any time'} → ${code.endsAt ?? 'no end'}`);
    }

    return lines.length > 0 ? lines : ['No conditions'];
}

function switchOff(code: PromoCode) {
    // Switched off, not deleted — the redemptions are the record of what the
    // campaign cost and of who has already used it.
    if (
        !confirm(
            `Switch off ${code.code}? Nobody new will be able to use it. Orders already placed keep their discount.`,
        )
    ) {
        return;
    }

    router.delete(route('admin.settings.promo-codes.destroy', code.uuid), { preserveScroll: true });
}

function CodeForm({ code, onClose }: { code: PromoCode | null; onClose: () => void }) {
    const [showLimits, setShowLimits] = useState(
        code !== null &&
            (code.minOrderNaira > 0 ||
                code.firstOrderOnly ||
                code.maxPerCustomer > 1 ||
                code.maxRedemptions !== null ||
                code.startsAt !== null ||
                code.endsAt !== null),
    );

    const form = useForm({
        code: code?.code ?? '',
        description: code?.description ?? '',
        type: code?.type ?? ('percent' as PromoType),
        percent_off: code?.percentOff ?? ('' as number | ''),
        amount_off_naira: code?.amountOffNaira ?? ('' as number | ''),
        max_discount_naira: code?.maxDiscountNaira ?? ('' as number | ''),
        min_order_naira: code?.minOrderNaira ?? 0,
        starts_at: code?.startsAt ?? '',
        ends_at: code?.endsAt ?? '',
        max_redemptions: code?.maxRedemptions ?? ('' as number | ''),
        max_per_customer: code?.maxPerCustomer ?? 1,
        first_order_only: code?.firstOrderOnly ?? false,
        is_active: code?.isActive ?? true,
    });

    /**
     * The code read back as one sentence. A type, a number and a cap in three
     * separate boxes do not add up in the head; this does, and it is the last
     * chance to notice that "10% up to ₦20" was meant to be ₦2,000.
     */
    const summary = useMemo(() => {
        const what =
            form.data.type === 'percent'
                ? `${form.data.percent_off || 0}% off${
                      form.data.max_discount_naira === ''
                          ? ''
                          : `, up to ${naira.format(Number(form.data.max_discount_naira))}`
                  }`
                : form.data.type === 'fixed'
                  ? `${naira.format(Number(form.data.amount_off_naira) || 0)} off`
                  : 'free delivery';

        const when =
            Number(form.data.min_order_naira) > 0
                ? ` on orders over ${naira.format(Number(form.data.min_order_naira))}`
                : '';

        const who = form.data.first_order_only ? ', for first orders only' : '';

        return `${form.data.code || 'This code'} gives ${what}${when}${who}.`;
    }, [form.data]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (code) {
            form.put(route('admin.settings.promo-codes.update', code.uuid), options);
        } else {
            form.post(route('admin.settings.promo-codes.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={code ? `Edit ${code.code}` : 'New promo code'}
            description="Two decisions: what comes off, and who may use it."
            size="xl"
        >
            <form onSubmit={submit} className="space-y-5">
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Code</span>
                        <Input
                            value={form.data.code}
                            onChange={(event) =>
                                form.setData('code', event.target.value.toUpperCase())
                            }
                            placeholder="SAVE10"
                            maxLength={32}
                            className="font-mono uppercase tracking-wide"
                        />
                        <p className="mt-1 text-[11px] text-gray-400">
                            Letters and numbers only — it gets typed by hand off a flyer.
                        </p>
                        <InputError message={form.errors.code} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">
                            What it does
                        </span>
                        <Select
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData('type', event.target.value as PromoType)
                            }
                        >
                            <option value="percent">Take a percentage off</option>
                            <option value="fixed">Take a fixed amount off</option>
                            <option value="free_delivery">Cover the delivery fee</option>
                        </Select>
                        <InputError message={form.errors.type} className="mt-1" />
                    </label>
                </div>

                {form.data.type === 'percent' && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="block">
                            <span className="mb-1 block text-xs font-semibold text-gray-600">
                                Percentage off
                            </span>
                            <Input
                                type="number"
                                step="0.01"
                                min={0.01}
                                max={100}
                                value={form.data.percent_off}
                                onChange={(event) =>
                                    form.setData(
                                        'percent_off',
                                        event.target.value === '' ? '' : Number(event.target.value),
                                    )
                                }
                            />
                            <InputError message={form.errors.percent_off} className="mt-1" />
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-xs font-semibold text-gray-600">
                                Never take off more than
                            </span>
                            <MoneyInput
                                min={0}
                                value={form.data.max_discount_naira}
                                onChange={(value: number | '') =>
                                    form.setData('max_discount_naira', value)
                                }
                            />
                            <p className="mt-1 text-[11px] text-gray-400">
                                Required. 10% is ₦500 on a kettle and ₦185,000 on a generator —
                                without a cap one order can spend the whole campaign.
                            </p>
                            <InputError message={form.errors.max_discount_naira} className="mt-1" />
                        </label>
                    </div>
                )}

                {form.data.type === 'fixed' && (
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">
                            Amount off
                        </span>
                        <MoneyInput
                            min={0}
                            value={form.data.amount_off_naira}
                            onChange={(value: number | '') => form.setData('amount_off_naira', value)}
                        />
                        <InputError message={form.errors.amount_off_naira} className="mt-1" />
                    </label>
                )}

                <p className="rounded-xl bg-brand-50 px-4 py-3 text-sm font-bold leading-relaxed text-brand-800">
                    {summary}
                </p>

                <button
                    type="button"
                    onClick={() => setShowLimits((open) => !open)}
                    className="flex w-full items-center justify-between rounded-xl bg-gray-50 px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100"
                >
                    <span>
                        Limits
                        <span className="ml-1.5 font-normal text-gray-400">
                            minimum order, dates, how many
                        </span>
                    </span>
                    {showLimits ? (
                        <ChevronUp className="h-4 w-4" />
                    ) : (
                        <ChevronDown className="h-4 w-4" />
                    )}
                </button>

                {showLimits && (
                    <div className="space-y-3 rounded-xl border border-gray-100 p-4">
                        <label className="block">
                            <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                Only on orders of at least — blank for any
                            </span>
                            <MoneyInput
                                min={0}
                                value={form.data.min_order_naira}
                                onChange={(value: number | '') =>
                                    form.setData('min_order_naira', value === '' ? 0 : value)
                                }
                            />
                        </label>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block">
                                <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                    Starts
                                </span>
                                <Input
                                    type="date"
                                    value={form.data.starts_at}
                                    onChange={(event) => form.setData('starts_at', event.target.value)}
                                />
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                    Ends
                                </span>
                                <Input
                                    type="date"
                                    value={form.data.ends_at}
                                    onChange={(event) => form.setData('ends_at', event.target.value)}
                                />
                                <InputError message={form.errors.ends_at} className="mt-1" />
                            </label>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block">
                                <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                    Total uses — blank for unlimited
                                </span>
                                <Input
                                    type="number"
                                    min={1}
                                    value={form.data.max_redemptions}
                                    onChange={(event) =>
                                        form.setData(
                                            'max_redemptions',
                                            event.target.value === ''
                                                ? ''
                                                : Number(event.target.value),
                                        )
                                    }
                                />
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                    Uses per customer
                                </span>
                                <Input
                                    type="number"
                                    min={1}
                                    value={form.data.max_per_customer}
                                    onChange={(event) =>
                                        form.setData('max_per_customer', Number(event.target.value))
                                    }
                                />
                            </label>
                        </div>

                        <label className="flex items-start gap-2.5 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={form.data.first_order_only}
                                onChange={(event) =>
                                    form.setData('first_order_only', event.target.checked)
                                }
                                className="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                            />
                            <span>
                                First order only
                                <span className="block text-[11px] text-gray-400">
                                    For winning new customers. Anyone who has ordered before is
                                    turned down.
                                </span>
                            </span>
                        </label>
                    </div>
                )}

                <label className="flex items-center gap-2.5 text-sm font-semibold text-gray-700">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        className="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                    />
                    Active
                </label>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl px-4 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 disabled:opacity-50"
                    >
                        {code ? 'Save changes' : 'Create code'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
