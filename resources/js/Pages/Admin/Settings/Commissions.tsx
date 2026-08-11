import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import { MoneyInput } from '@/Components/ui/MoneyInput';
import PageHeader from '@/Components/ui/PageHeader';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Boxes, ChevronDown, ChevronUp, Layers, Pencil, Plus, Store, Tag, Trash2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type ScopeType = 'global' | 'category' | 'vendor' | 'product';

interface Rule {
    uuid: string;
    scopeType: ScopeType;
    scopeId: number | null;
    scopeLabel: string;
    minPriceNaira: number;
    maxPriceNaira: number | null;
    ratePercent: number;
    maxCommissionNaira: number | null;
    isActive: boolean;
    note: string | null;
    updatedBy: string | null;
}

interface Props {
    rules: Rule[];
    defaultRatePercent: number;
    categories: { id: number; name: string }[];
    vendors: { id: number; name: string }[];
    products: { id: number; name: string; categoryId: number; vendorId: number; priceNaira: number }[];
    [key: string]: unknown;
}

const naira = new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 0,
});

const SCOPE_META: Record<ScopeType, { label: string; icon: typeof Tag; tone: string }> = {
    product: { label: 'Product', icon: Boxes, tone: 'bg-violet-50 text-violet-700' },
    vendor: { label: 'Vendor', icon: Store, tone: 'bg-indigo-50 text-indigo-700' },
    category: { label: 'Category', icon: Tag, tone: 'bg-sky-50 text-sky-700' },
    global: { label: 'Everything', icon: Layers, tone: 'bg-gray-100 text-gray-600' },
};

/** How a rule's price band reads on one line. */
function bandLabel(rule: Rule): string {
    if (rule.minPriceNaira === 0 && rule.maxPriceNaira === null) return 'Any price';
    if (rule.maxPriceNaira === null) return `${naira.format(rule.minPriceNaira)} and above`;
    if (rule.minPriceNaira === 0) return `Under ${naira.format(rule.maxPriceNaira)}`;

    return `${naira.format(rule.minPriceNaira)} – ${naira.format(rule.maxPriceNaira)}`;
}

/**
 * Mirrors CommissionRule::commissionOn so the preview cannot drift from what
 * the server will actually charge.
 */
function commissionFor(rule: Rule, priceNaira: number): number {
    let commission = (priceNaira * rule.ratePercent) / 100;

    if (rule.maxCommissionNaira !== null) commission = Math.min(commission, rule.maxCommissionNaira);

    return Math.max(0, Math.min(commission, priceNaira));
}

/**
 * Commission rules.
 *
 * Replaces a flat percentage per category, which could not price a catalogue
 * honestly: two items in one category at ₦500 and ₦5,000 cost the same to
 * process and deliver but earn ten times apart. A rule now carries a scope, a
 * price band, and optional floors and ceilings, and the most specific match
 * wins.
 */
export default function CommissionSettings() {
    // Defaulted, not assumed. Inertia restores page props from history on
    // a back/forward, so a visit made before a prop existed can render
    // this component without it — and a bare .filter() on undefined takes
    // the whole screen down rather than degrading.
    const {
        rules = [],
        // Zero out of the box: FirstMaket taking a cut on a sale nobody set a
        // rate for is a decision, not a default.
        defaultRatePercent = 0,
        categories = [],
        vendors = [],
        products = [],
    } = usePage<Props>().props;

    const [editing, setEditing] = useState<Rule | null>(null);
    const [adding, setAdding] = useState(false);
    const defaultRateForm = useForm({ default_rate_percent: String(defaultRatePercent) });
    const [testPrice, setTestPrice] = useState<number | ''>('');

    function remove(rule: Rule) {
        if (!confirm(`Delete this rule for ${rule.scopeLabel}? Sales it covered fall to the next match.`)) return;

        router.delete(route('admin.settings.commissions.destroy', rule.uuid), { preserveScroll: true });
    }

    return (
        <AdminLayout>
            <Head title="Commission rules" />

            <PageHeader
                eyebrow="Vendor settlement"
                title="Commission rules"
                description="What FirstMaket takes on a sale, and on which sales. Orders keep the commission they were created with, so changes here only affect what happens next."
                actions={
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700"
                    >
                        <Plus className="h-4 w-4" /> Add a rule
                    </button>
                }
            />

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="min-w-0 space-y-4">
                    {/* One line and a visual chain. The old version was a
                        paragraph nobody finishes reading. */}
                    <Card className="flex flex-wrap items-center gap-x-2 gap-y-2 text-sm">
                        <span className="font-semibold text-gray-900">Most specific wins:</span>
                        {(['product', 'vendor', 'category', 'global'] as ScopeType[]).map((scope, index) => (
                            <span key={scope} className="flex items-center gap-2">
                                {index > 0 && <span className="text-gray-300">→</span>}
                                <span
                                    className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${SCOPE_META[scope].tone}`}
                                >
                                    {SCOPE_META[scope].label}
                                </span>
                            </span>
                        ))}
                        <span className="text-gray-300">→</span>
                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-gray-500">
                            {defaultRatePercent}% default
                        </span>
                    </Card>

                    {/* ── The fallback rate ──
                        Shown on this page for a long time with no way to
                        change it, which left it pinned at 0 — so any sale
                        outside every rule earned nothing. */}
                    <Card>
                        <h2 className="text-sm font-bold text-gray-900">Default rate</h2>
                        <p className="mt-1 text-sm text-gray-500">
                            Charged on any sale no rule below matches. Orders already placed keep the
                            rate they were created with.
                        </p>

                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                defaultRateForm.post(route('admin.settings.commissions.default-rate'), {
                                    preserveScroll: true,
                                });
                            }}
                            className="mt-3 flex flex-wrap items-end gap-3"
                        >
                            <div className="w-40">
                                <label
                                    htmlFor="default_rate"
                                    className="mb-1.5 block text-xs font-bold text-gray-700"
                                >
                                    Percent of each sale
                                </label>
                                <Input
                                    id="default_rate"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value={defaultRateForm.data.default_rate_percent}
                                    onChange={(event) =>
                                        defaultRateForm.setData('default_rate_percent', event.target.value)
                                    }
                                />
                                <InputError
                                    message={defaultRateForm.errors.default_rate_percent}
                                    className="mt-1"
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={defaultRateForm.processing}
                                className="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:opacity-60"
                            >
                                {defaultRateForm.processing ? 'Saving…' : 'Save default'}
                            </button>
                        </form>
                    </Card>

                    {/* ── The rules ── */}
                    {rules.length === 0 ? (
                        <Card className="flex flex-col items-center px-6 py-14 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                                <Layers className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">No rules yet</p>
                            <p className="mt-1 max-w-md text-sm text-gray-500">
                                Every sale currently takes the {defaultRatePercent}% default. Add a rule to
                                charge differently by category, vendor, product or price.
                            </p>
                            <button
                                type="button"
                                onClick={() => setAdding(true)}
                                className="mt-4 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700"
                            >
                                + Add the first rule
                            </button>
                        </Card>
                    ) : (
                        <Card className="overflow-hidden p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[840px] text-sm">
                                    <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th className="px-5 py-3 font-semibold">Applies to</th>
                                            <th className="px-4 py-3 font-semibold">Price band</th>
                                            <th className="px-4 py-3 text-right font-semibold">Rate</th>
                                            <th className="px-4 py-3 text-right font-semibold">Maximum</th>
                                            <th className="px-4 py-3 font-semibold">Status</th>
                                            <th className="px-5 py-3 text-right font-semibold">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {rules.map((rule) => {
                                            const meta = SCOPE_META[rule.scopeType];
                                            const Icon = meta.icon;

                                            return (
                                                <tr
                                                    key={rule.uuid}
                                                    className={`transition-colors hover:bg-slate-50/60 ${
                                                        rule.isActive ? '' : 'opacity-50'
                                                    }`}
                                                >
                                                    <td className="px-5 py-3">
                                                        <span
                                                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ${meta.tone}`}
                                                        >
                                                            <Icon className="h-3 w-3" /> {meta.label}
                                                        </span>
                                                        <span className="mt-0.5 block font-semibold text-gray-900">
                                                            {rule.scopeLabel}
                                                        </span>
                                                        {rule.note && (
                                                            <span className="block text-xs text-gray-400">
                                                                {rule.note}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 tabular-nums text-gray-600">
                                                        {bandLabel(rule)}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <span className="font-bold tabular-nums text-gray-900">
                                                            {rule.ratePercent}%
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-xs tabular-nums text-gray-500">
                                                        {rule.maxCommissionNaira === null ? (
                                                            <span className="text-gray-300">no cap</span>
                                                        ) : (
                                                            naira.format(rule.maxCommissionNaira)
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${
                                                                rule.isActive
                                                                    ? 'bg-emerald-50 text-emerald-700'
                                                                    : 'bg-gray-100 text-gray-500'
                                                            }`}
                                                        >
                                                            {rule.isActive ? 'Live' : 'Off'}
                                                        </span>
                                                    </td>
                                                    <td className="px-5 py-3 text-right">
                                                        <span className="inline-flex items-center gap-1">
                                                            <button
                                                                type="button"
                                                                onClick={() => setEditing(rule)}
                                                                aria-label={`Edit rule for ${rule.scopeLabel}`}
                                                                className="rounded-lg p-1.5 text-gray-400 transition hover:bg-brand-50 hover:text-brand-700"
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => remove(rule)}
                                                                aria-label={`Delete rule for ${rule.scopeLabel}`}
                                                                className="rounded-lg p-1.5 text-gray-300 transition hover:bg-red-50 hover:text-red-600"
                                                            >
                                                                <Trash2 className="h-4 w-4" />
                                                            </button>
                                                        </span>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    )}
                </div>

                {/* ── Try a price ──
                    Banded rules are easy to get subtly wrong, and the cost of
                    finding out is a mispriced order. This answers "what would
                    you charge for this" before anybody trusts the table. */}
                <Card className="h-fit">
                    <p className="text-xs font-bold uppercase tracking-wide text-gray-500">Try a price</p>
                    <p className="mt-1 text-xs text-gray-400">
                        Check what each rule would take, without saving anything.
                    </p>

                    <div className="mt-3">
                        <MoneyInput
                            min={0}
                            value={testPrice}
                            onChange={(value: number | '') => setTestPrice(value)}
                            placeholder="e.g. 5000"
                        />
                    </div>

                    {testPrice !== '' && testPrice > 0 && (
                        <ul className="mt-3 space-y-2">
                            {rules.filter((rule) => rule.isActive).length === 0 && (
                                <li className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">
                                    No live rules — this sale would take the {defaultRatePercent}% default,{' '}
                                    {naira.format((testPrice * defaultRatePercent) / 100)}.
                                </li>
                            )}
                            {rules
                                .filter(
                                    (rule) =>
                                        rule.isActive &&
                                        testPrice >= rule.minPriceNaira &&
                                        (rule.maxPriceNaira === null || testPrice < rule.maxPriceNaira),
                                )
                                .map((rule, index) => {
                                    const amount = commissionFor(rule, testPrice);

                                    return (
                                        <li
                                            key={rule.uuid}
                                            className={`rounded-lg border px-3 py-2 ${
                                                index === 0
                                                    ? 'border-brand-200 bg-brand-50/60'
                                                    : 'border-gray-100 bg-white opacity-60'
                                            }`}
                                        >
                                            <span className="flex items-center justify-between gap-2">
                                                <span className="truncate text-xs font-semibold text-gray-900">
                                                    {rule.scopeLabel}
                                                </span>
                                                <span className="shrink-0 text-sm font-bold tabular-nums text-gray-900">
                                                    {naira.format(amount)}
                                                </span>
                                            </span>
                                            <span className="text-[11px] text-gray-500">
                                                {index === 0
                                                    ? `Wins — ${((amount / testPrice) * 100).toFixed(1)}% effective`
                                                    : 'Also matches, but less specific'}
                                            </span>
                                        </li>
                                    );
                                })}
                        </ul>
                    )}

                    
                </Card>
            </div>

            {(adding || editing) && (
                <RuleForm
                    rule={editing}
                    categories={categories}
                    vendors={vendors}
                    products={products}
                    onDone={() => {
                        setAdding(false);
                        setEditing(null);
                    }}
                />
            )}
        </AdminLayout>
    );
}

function RuleForm({
    rule,
    categories,
    vendors,
    products,
    onDone,
}: {
    rule: Rule | null;
    categories: Props['categories'];
    vendors: Props['vendors'];
    products: Props['products'];
    onDone: () => void;
}) {
    const form = useForm({
        scope_type: rule?.scopeType ?? ('category' as ScopeType),
        scope_id: rule?.scopeId ?? ('' as number | ''),
        min_price_naira: rule?.minPriceNaira ?? 0,
        max_price_naira: rule?.maxPriceNaira ?? ('' as number | ''),
        rate_percent: rule?.ratePercent ?? 10,
        max_commission_naira: rule?.maxCommissionNaira ?? ('' as number | ''),
        is_active: rule?.isActive ?? true,
        note: rule?.note ?? '',
    });

    const scoped = form.data.scope_type !== 'global';

    // A catalogue is too long to scroll, so it is narrowed first. Both
    // filters are optional and affect only what is listed — neither is
    // saved on the rule.
    const [filterCategory, setFilterCategory] = useState<number | ''>('');
    const [filterVendor, setFilterVendor] = useState<number | ''>('');

    const visibleProducts = products.filter(
        (product) =>
            (filterCategory === '' || product.categoryId === filterCategory) &&
            (filterVendor === '' || product.vendorId === filterVendor),
    );
    // Opened already when editing a rule that uses any of it, so nothing
    // a rule actually relies on is hidden behind a click.
    const [advanced, setAdvanced] = useState(
        rule !== null &&
            (rule.minPriceNaira > 0 ||
                rule.maxPriceNaira !== null ||
                rule.maxCommissionNaira !== null),
    );

    const example = useMemo(() => {
        const from = Number(form.data.min_price_naira) || 0;
        const to = form.data.max_price_naira === '' ? null : Number(form.data.max_price_naira);

        // Preview at a price the rule ACTUALLY covers. This used to ignore
        // "From" entirely and quote ₦5,000 for a rule starting at ₦850,000,
        // so the figure described a sale the rule would never touch.
        const price = from > 0 ? from : to !== null ? Math.max(1, to - 1) : 5000;

        const rate = Number(form.data.rate_percent || 0);
        const uncapped = (price * rate) / 100;
        const cap = form.data.max_commission_naira === ''
            ? null
            : Number(form.data.max_commission_naira);
        const amount = Math.max(0, Math.min(cap === null ? uncapped : Math.min(uncapped, cap), price));

        return {
            price,
            amount,
            // A cap that swallows almost the whole percentage is nearly
            // always a typo — ₦2 where ₦2,000 was meant. Worth saying so
            // rather than quietly showing "0.0% effective".
            capSwallowsRate: cap !== null && uncapped > 0 && cap < uncapped / 2,
            uncapped,
        };
    }, [form.data]);

    /** The whole rule as one sentence, so it can be read back before saving. */
    const summary = useMemo(() => {
        const what =
            form.data.scope_type === 'global'
                ? 'Every item'
                : form.data.scope_type === 'category'
                  ? `${categories.find((c) => c.id === form.data.scope_id)?.name ?? 'A category'} items`
                  : form.data.scope_type === 'vendor'
                    ? `Items from ${vendors.find((v) => v.id === form.data.scope_id)?.name ?? 'a vendor'}`
                    : `${products.find((p) => p.id === form.data.scope_id)?.name ?? 'One product'}`;

        const from = Number(form.data.min_price_naira) || 0;
        const to = form.data.max_price_naira === '' ? null : Number(form.data.max_price_naira);

        const band =
            from === 0 && to === null
                ? ''
                : to === null
                  ? ` priced ${naira.format(from)} and above`
                  : from === 0
                    ? ` priced under ${naira.format(to)}`
                    : ` priced ${naira.format(from)} to ${naira.format(to)}`;

        const cap =
            form.data.max_commission_naira === ''
                ? ''
                : `, but never more than ${naira.format(Number(form.data.max_commission_naira))}`;

        return `${what}${band}: we take ${form.data.rate_percent}%${cap}.`;
    }, [form.data, categories, vendors, products]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onDone() };

        if (rule) {
            form.put(route('admin.settings.commissions.update', rule.uuid), options);
        } else {
            form.post(route('admin.settings.commissions.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onDone}
            title={rule ? 'Edit this rule' : 'Add a commission rule'}
            description="Two decisions: what it covers, and how much we take."
            size="xl"
            footer={
                <>
                    <button
                        type="button"
                        onClick={onDone}
                        className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="commission-rule-form"
                        disabled={form.processing}
                        className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                    >
                        {form.processing ? 'Saving…' : 'Save rule'}
                    </button>
                </>
            }
        >
            <form id="commission-rule-form" onSubmit={submit} className="space-y-4">
                {/* Step 1: what. Buttons rather than a dropdown labelled
                    "Applies to" — the four options ARE the model, so showing
                    them costs nothing and explains itself. */}
                <div>
                    <span className="mb-1.5 block text-xs font-bold text-gray-700">
                        1. What does this apply to?
                    </span>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {(['global', 'category', 'vendor', 'product'] as ScopeType[]).map((scope) => {
                            const meta = SCOPE_META[scope];
                            const Icon = meta.icon;
                            const active = form.data.scope_type === scope;

                            return (
                                <button
                                    key={scope}
                                    type="button"
                                    onClick={() => {
                                        form.setData('scope_type', scope);
                                        form.setData('scope_id', '');
                                    }}
                                    className={`flex flex-col items-center gap-1 rounded-xl border-2 px-2 py-3 text-center transition ${
                                        active
                                            ? 'border-brand-600 bg-brand-50/60'
                                            : 'border-gray-200 hover:border-brand-300'
                                    }`}
                                >
                                    <Icon className="h-4 w-4 text-gray-500" />
                                    <span className="text-xs font-bold text-gray-900">{meta.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {scoped && (
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">
                            Which {form.data.scope_type}?
                        </span>
                        {form.data.scope_type === 'product' ? (
                            <>
                                {/* Narrow first, then pick. Nobody knows a
                                    product by its database id. */}
                                <div className="mb-2 grid gap-2 sm:grid-cols-2">
                                    <Select
                                        value={String(filterCategory)}
                                        onChange={(e) =>
                                            setFilterCategory(
                                                e.target.value === '' ? '' : Number(e.target.value),
                                            )
                                        }
                                    >
                                        <option value="">All categories</option>
                                        {categories.map((category) => (
                                            <option key={category.id} value={category.id}>
                                                {category.name}
                                            </option>
                                        ))}
                                    </Select>
                                    <Select
                                        value={String(filterVendor)}
                                        onChange={(e) =>
                                            setFilterVendor(
                                                e.target.value === '' ? '' : Number(e.target.value),
                                            )
                                        }
                                    >
                                        <option value="">All vendors</option>
                                        {vendors.map((vendor) => (
                                            <option key={vendor.id} value={vendor.id}>
                                                {vendor.name}
                                            </option>
                                        ))}
                                    </Select>
                                </div>

                                <Select
                                    value={String(form.data.scope_id)}
                                    onChange={(e) =>
                                        form.setData(
                                            'scope_id',
                                            e.target.value === '' ? '' : Number(e.target.value),
                                        )
                                    }
                                >
                                    <option value="">
                                        {visibleProducts.length === 0
                                            ? 'Nothing matches those filters'
                                            : `Choose one of ${visibleProducts.length}…`}
                                    </option>
                                    {visibleProducts.map((product) => (
                                        <option key={product.id} value={product.id}>
                                            {product.name} — {naira.format(product.priceNaira)}
                                        </option>
                                    ))}
                                </Select>
                            </>
                        ) : (
                            <Select
                                value={String(form.data.scope_id)}
                                onChange={(e) =>
                                    form.setData('scope_id', e.target.value === '' ? '' : Number(e.target.value))
                                }
                            >
                                <option value="">Choose one…</option>
                                {(form.data.scope_type === 'category' ? categories : vendors).map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                        <InputError message={form.errors.scope_id} className="mt-1" />
                    </label>
                )}

                {/* Step 2: how much. The only other thing most rules need. */}
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-gray-700">
                        2. How much do we take?
                    </span>
                    <div className="flex items-center gap-2">
                        <input
                            type="number"
                            min={0}
                            max={100}
                            step="0.01"
                            value={form.data.rate_percent}
                            onChange={(e) => form.setData('rate_percent', Number(e.target.value))}
                            className="w-28 rounded-lg border border-gray-300 px-3.5 py-2.5 text-lg font-bold transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                        />
                        <span className="text-lg font-bold text-gray-400">%</span>
                        <span className="text-sm text-gray-500">of the item price</span>
                    </div>
                    <InputError message={form.errors.rate_percent} className="mt-1" />
                </label>

                <div className="rounded-xl bg-brand-50 px-4 py-3">
                    {/* The rule read back in a sentence. A percentage and a
                        cap in separate boxes do not add up in the head; this
                        does. */}
                    <p className="text-sm font-bold leading-relaxed text-brand-800">{summary}</p>
                    <p className="mt-1.5 text-xs text-brand-700">
                        On a {naira.format(example.price)} sale that is{' '}
                        <strong>{naira.format(example.amount)}</strong>
                        {example.price > 0 && (
                            <> ({((example.amount / example.price) * 100).toFixed(1)}% of the price)</>
                        )}
                        .
                    </p>
                </div>

                {example.capSwallowsRate && (
                    <p className="rounded-xl bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-800">
                        <strong>Check the maximum.</strong> {form.data.rate_percent}% of{' '}
                        {naira.format(example.price)} is {naira.format(example.uncapped)}, but the cap holds
                        it to {naira.format(example.amount)} — so the percentage barely matters. If you meant
                        a larger cap, raise it; if you meant this, it is fine.
                    </p>
                )}

                {/* Everything below serves the minority of rules that need it.
                    Folded away so the common case is two decisions, not nine. */}
                <button
                    type="button"
                    onClick={() => setAdvanced((open) => !open)}
                    className="flex w-full items-center justify-between rounded-xl border border-gray-200 px-3.5 py-2.5 text-left text-xs font-bold text-gray-700 transition hover:bg-gray-50"
                >
                    <span>
                        More options
                        <span className="ml-1.5 font-normal text-gray-400">
                            price range and a maximum
                        </span>
                    </span>
                    {advanced ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                </button>

                {advanced && (
                    <div className="space-y-4 rounded-xl bg-slate-50/70 p-3">
                        <div>
                            <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                Only apply this to items costing
                            </span>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="block">
                                    <span className="mb-1 block text-[11px] font-semibold text-gray-500">From</span>
                                    <MoneyInput
                                        min={0}
                                        value={form.data.min_price_naira}
                                        onChange={(value: number | '') =>
                                            form.setData('min_price_naira', value === '' ? 0 : value)
                                        }
                                    />
                                </label>
                                <label className="block">
                                    <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                        Up to (leave blank for no limit)
                                    </span>
                                    <MoneyInput
                                        min={0}
                                        value={form.data.max_price_naira}
                                        onChange={(value: number | '') => form.setData('max_price_naira', value)}
                                    />
                                    <InputError message={form.errors.max_price_naira} className="mt-1" />
                                </label>
                            </div>
                            <p className="mt-1.5 text-[11px] text-gray-400">
                                Leave both blank to cover every price. Filling these is how one category can charge
                                one rate on cheap items and another on expensive ones.
                            </p>
                        </div>

                        <label className="block">
                            <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                Cap our cut at (in naira, leave blank for no cap)
                            </span>
                            <MoneyInput
                                min={0}
                                value={form.data.max_commission_naira}
                                onChange={(value: number | '') =>
                                    form.setData('max_commission_naira', value)
                                }
                            />
                            <p className="mt-1 text-[11px] text-gray-400">
                                On a ₦1,850,000 listing a plain 10% takes ₦185,000, which no vendor
                                accepts. A cap keeps the percentage honest at the top end.
                            </p>
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-[11px] font-semibold text-gray-500">
                                Note — staff only
                            </span>
                            <input
                                type="text"
                                maxLength={200}
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                placeholder="Why this rule exists"
                                className="w-full rounded-lg border border-gray-300 px-3.5 py-2 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                            />
                        </label>
                    </div>
                )}

                <label className="flex items-center gap-2.5">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                        className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                    />
                    <span className="text-sm text-gray-700">Use this rule on new orders</span>
                </label>
            </form>
        </Modal>
    );
}
