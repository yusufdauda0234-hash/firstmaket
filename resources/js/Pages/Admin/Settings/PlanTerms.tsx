import TemplatePicker, { Template } from '@/Components/domain/admin/TemplatePicker';
import { Badge } from '@/Components/ui/Badge';
import BulkActionBar from '@/Components/ui/BulkActionBar';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import { Select } from '@/Components/ui/Select';
import ViewToggle from '@/Components/ui/ViewToggle';
import { useRowSelection } from '@/Hooks/useRowSelection';
import { useViewMode } from '@/Hooks/useViewMode';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarClock, ChevronDown, ChevronUp, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { MoneyInput } from '@/Components/ui/MoneyInput';

interface Term {
    id: number;
    name: string;
    cadence: string;
    cadenceLabel: string;
    installments: number;
    durationMonths: number;
    durationLabel: string;
    minTargetNaira: number;
    /** 0 = the first instalment is charged at checkout. */
    firstPaymentDueDays: number;
    firstPaymentLabel: string;
    missedPaymentsAllowed: number;
    isActive: boolean;
    planCount: number;
    activePlanCount: number;
}

/** Exact integers from PlanCadence::math(), so the preview cannot drift. */
interface CadenceMath {
    perMonth: number | null;
    monthsPer: number | null;
}

interface Props {
    /** Ready-made settings an admin can apply in one click. */
    templates: Template[];
    terms: Term[];
    cadences: { value: string; label: string; durations: number[] }[];
    cadenceMath: Record<string, CadenceMath>;
    [key: string]: unknown;
}

/** Mirrors PlanCadence::installmentsFor exactly. */
function paymentsFor(math: CadenceMath | undefined, months: number): number {
    const run = Math.max(1, months);

    if (!math) {
        return run;
    }

    // Yearly divides the other way round; flooring keeps a term from ever
    // claiming more payments than its duration contains.
    return math.monthsPer ? Math.floor(run / math.monthsPer) : run * (math.perMonth ?? 1);
}

const naira = new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 0,
});

/**
 * Pay Small Small terms — the rhythms customers may choose at checkout.
 *
 * A term carries no money. It is a cadence plus a duration; the payment is
 * always the customer's own order total divided by the payment count, worked
 * out at checkout against what is actually in their basket. This screen used
 * to quote every term against a fixed ₦100,000, which invited the reading that
 * a term sets a price — it does not.
 */
export default function PlanTerms() {
    const { terms, cadences, cadenceMath, templates = [] } = usePage<Props>().props;
    const [editing, setEditing] = useState<Term | null>(null);
    const [creating, setCreating] = useState(false);
    const { mode, choose } = useViewMode('admin.plan-terms', 'table');

    const selection = useRowSelection(terms.map((t) => String(t.id)));
    const bulk = useForm<{ action: string; ids: number[] }>({ action: 'activate', ids: [] });

    function runBulk(action: 'activate' | 'deactivate') {
        bulk.transform(() => ({ action, ids: selection.ids.map(Number) }));
        bulk.post(route('admin.settings.plan-terms.bulk'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    function remove(term: Term) {
        if (confirm(`Delete "${term.name}"? It will stop being offered at checkout.`)) {
            router.delete(route('admin.settings.plan-terms.destroy', term.id), { preserveScroll: true });
        }
    }

    const activeCount = terms.filter((t) => t.isActive).length;

    return (
        <AdminLayout>
            <Head title="Plan terms" />

            <PageHeader
                title="Pay Small Small terms"
                description={`How customers may spread payment at checkout. ${activeCount} offered right now.`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <ViewToggle mode={mode} onChange={choose} label="terms" />
                        <TemplatePicker
                            templates={templates}
                            action={route('admin.settings.plan-terms.template')}
                            noun="instalment plans"
                            empty={terms.length === 0}
                        />
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(null);
                                setCreating(true);
                            }}
                            className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-700"
                        >
                            <Plus className="h-4 w-4" /> Add a term
                        </button>
                    </div>
                }
            />


            <Card className="overflow-hidden p-0">
                {terms.length === 0 ? (
                    <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
                        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                            <CalendarClock className="h-7 w-7" />
                        </span>
                        <p className="mt-4 text-sm font-medium text-gray-900">No terms yet</p>
                        <p className="mt-1 text-sm text-gray-500">
                            Add one and it appears at checkout immediately.
                        </p>
                    </div>
                ) : mode === 'table' ? (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[820px] text-sm">
                            <thead className="border-b border-gray-100 bg-gray-50/70 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="w-10 py-3 pl-5 pr-2">
                                        <RowCheckbox
                                            checked={selection.allSelected}
                                            indeterminate={selection.someSelected}
                                            onChange={selection.toggleAll}
                                            label="Select all terms"
                                        />
                                    </th>
                                    <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                    <th className="px-5 py-3 font-semibold">Term</th>
                                    <th className="px-4 py-3 font-semibold">Pays</th>
                                    <th className="px-4 py-3 font-semibold">Each payment</th>
                                    <th className="px-4 py-3 font-semibold">Minimum order</th>
                                    <th className="px-4 py-3 font-semibold">First payment</th>
                                    <th className="px-4 py-3 font-semibold">Used by</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {terms.map((term, index) => (
                                    <tr
                                        key={term.id}
                                        className={`transition-colors hover:bg-brand-50/50 ${
                                            selection.isSelected(String(term.id)) ? 'bg-brand-50/70' : ''
                                        }`}
                                    >
                                        <td className="py-3.5 pl-5 pr-2">
                                            <RowCheckbox
                                                checked={selection.isSelected(String(term.id))}
                                                onChange={() => selection.toggle(String(term.id))}
                                                label={`Select ${term.name}`}
                                            />
                                        </td>
                                        <td className="px-2 py-3.5 text-xs tabular-nums text-gray-400">
                                            {index + 1}
                                        </td>
                                        <td className="px-5 py-3.5 font-semibold text-gray-900">{term.name}</td>
                                        <td className="px-4 py-3.5 text-gray-600">
                                            {term.cadenceLabel}
                                            <span className="block text-xs text-gray-400">
                                                {term.installments} payments over {term.durationLabel}
                                            </span>
                                        </td>
                                        {/* A formula, not a figure — the amount depends on the
                                            customer's own basket. */}
                                        <td className="px-4 py-3.5 font-mono text-xs text-gray-500">
                                            order ÷ {term.installments}
                                        </td>
                                        <td className="px-4 py-3.5 tabular-nums text-gray-600">
                                            {term.minTargetNaira > 0 ? (
                                                naira.format(term.minTargetNaira)
                                            ) : (
                                                <span className="text-gray-300">any</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span
                                                className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold ${
                                                    term.firstPaymentDueDays === 0
                                                        ? 'bg-amber-50 text-amber-700'
                                                        : 'bg-emerald-50 text-emerald-700'
                                                }`}
                                            >
                                                {term.firstPaymentDueDays === 0
                                                    ? 'At checkout'
                                                    : `${term.firstPaymentDueDays} day${
                                                          term.firstPaymentDueDays === 1 ? '' : 's'
                                                      }`}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 tabular-nums text-gray-500">
                                            {term.planCount > 0 ? (
                                                <>
                                                    {term.planCount} plan{term.planCount === 1 ? '' : 's'}
                                                    {term.activePlanCount > 0 && (
                                                        <span className="block text-xs text-emerald-600">
                                                            {term.activePlanCount} running
                                                        </span>
                                                    )}
                                                </>
                                            ) : (
                                                <span className="text-gray-300">—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            {term.isActive ? (
                                                <Badge tone="success">Active</Badge>
                                            ) : (
                                                <Badge tone="warning">Hidden</Badge>
                                            )}
                                        </td>
                                        <td className="px-5 py-3.5">
                                            <div className="flex items-center justify-end gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setCreating(false);
                                                        setEditing(term);
                                                    }}
                                                    aria-label={`Edit ${term.name}`}
                                                    className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => remove(term)}
                                                    disabled={term.planCount > 0}
                                                    title={
                                                        term.planCount > 0
                                                            ? 'Customers have used this term — switch it off instead'
                                                            : 'Delete'
                                                    }
                                                    aria-label={`Delete ${term.name}`}
                                                    className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-gray-400"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
                        {terms.map((term) => (
                            <div
                                key={term.id}
                                className={`flex flex-col rounded-xl border p-4 transition ${
                                    selection.isSelected(String(term.id))
                                        ? 'border-brand-300 bg-brand-50/60'
                                        : 'border-gray-100 hover:border-brand-200 hover:shadow-md hover:shadow-brand-600/5'
                                }`}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <RowCheckbox
                                        checked={selection.isSelected(String(term.id))}
                                        onChange={() => selection.toggle(String(term.id))}
                                        label={`Select ${term.name}`}
                                    />
                                    {term.isActive ? (
                                        <Badge tone="success">Active</Badge>
                                    ) : (
                                        <Badge tone="warning">Hidden</Badge>
                                    )}
                                </div>

                                <span className="mt-3 flex items-center gap-2 font-bold text-gray-900">
                                    <CalendarClock className="h-4 w-4 shrink-0 text-brand-600" />
                                    {term.name}
                                </span>

                                <span className="mt-1 text-sm text-gray-500">
                                    {term.cadenceLabel} · {term.installments} payments over {term.durationLabel}
                                </span>

                                <span className="mt-2 font-mono text-xs text-gray-500">
                                    each payment = order ÷ {term.installments}
                                </span>

                                <span
                                    className={`mt-2 w-fit rounded-full px-2 py-0.5 text-[11px] font-bold ${
                                        term.firstPaymentDueDays === 0
                                            ? 'bg-amber-50 text-amber-700'
                                            : 'bg-emerald-50 text-emerald-700'
                                    }`}
                                >
                                    {term.firstPaymentLabel}
                                </span>

                                <span className="mt-3 flex items-center justify-between border-t border-gray-100 pt-2.5 text-xs text-gray-400">
                                    <span>
                                        {term.minTargetNaira > 0
                                            ? `min ${naira.format(term.minTargetNaira)}`
                                            : 'any order'}
                                    </span>
                                    <span>
                                        {term.planCount > 0 ? `${term.planCount} used` : 'unused'}
                                    </span>
                                </span>

                                <div className="mt-3 flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setCreating(false);
                                            setEditing(term);
                                        }}
                                        className="flex-1 rounded-full border border-gray-200 py-1.5 text-xs font-bold text-gray-700 transition hover:border-brand-300 hover:text-brand-700"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => remove(term)}
                                        disabled={term.planCount > 0}
                                        aria-label={`Delete ${term.name}`}
                                        className="rounded-full p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-30"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            <BulkActionBar
                count={selection.count}
                noun="term"
                processing={bulk.processing}
                onClear={selection.clear}
                actions={[
                    { label: 'Switch on', tone: 'primary', run: () => runBulk('activate') },
                    { label: 'Switch off', tone: 'neutral', run: () => runBulk('deactivate') },
                ]}
            />

            <Modal
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                title={editing ? `Edit “${editing.name}”` : 'Add an instalment plan'}
                description="Customers see the name, and it is written from the two choices below."
                size="xl"
            >
                <TermForm
                    term={editing ?? undefined}
                    cadences={cadences}
                    cadenceMath={cadenceMath}
                    onDone={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            </Modal>
        </AdminLayout>
    );
}

function TermForm({
    term,
    cadences,
    cadenceMath,
    onDone,
}: {
    term?: Term;
    cadences: { value: string; label: string; durations: number[] }[];
    cadenceMath: Record<string, CadenceMath>;
    onDone?: () => void;
}) {
    const form = useForm({
        cadence: term?.cadence ?? cadences[0]?.value ?? 'monthly',
        duration_months: term?.durationMonths ?? 3,
        min_target_naira: term?.minTargetNaira ?? 0,
        first_payment_due_days: term?.firstPaymentDueDays ?? 0,
        missed_payments_allowed: term?.missedPaymentsAllowed ?? 3,
        is_active: term?.isActive ?? true,
    });

    // Optional sanity check. Empty by default, so this screen never quotes a
    // figure the business has not been asked about.
    const [tryOrder, setTryOrder] = useState('');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onDone?.() };

        if (term) {
            form.put(route('admin.settings.plan-terms.update', term.id), options);
        } else {
            form.post(route('admin.settings.plan-terms.store'), {
                ...options,
                onSuccess: () => {
                    form.reset();
                    onDone?.();
                },
            });
        }
    };

    const [showDeadlines, setShowDeadlines] = useState(false);

    // The runs this rhythm offers. A free number box asked the admin to work
    // out which combinations make sense; the server decides instead.
    const durationChoices =
        cadences.find((c) => c.value === form.data.cadence)?.durations ?? [3];

    // Built from the server's own integers, so the form previews exactly what
    // will be stored rather than a second copy of the rules.
    const payments = paymentsFor(cadenceMath[form.data.cadence], form.data.duration_months);

    const trial = Number(tryOrder);
    // Mirrors PlanTerm::installmentKoboFor — rounded up so the payments always
    // cover the order.
    const perPayment = trial > 0 && payments > 0 ? Math.ceil(trial / payments) : null;

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-gray-700">How often</span>
                    <Select
                        value={form.data.cadence}
                        onChange={(e) => {
                            const next = e.target.value;
                            const choices =
                                cadences.find((c) => c.value === next)?.durations ?? [];

                            // Keep the current run if the new rhythm offers it,
                            // otherwise take its first. Leaving "24 months" set
                            // after switching to daily would be 720 payments,
                            // and the form would be showing a term nobody could
                            // save.
                            form.setData({
                                ...form.data,
                                cadence: next,
                                duration_months: choices.includes(form.data.duration_months)
                                    ? form.data.duration_months
                                    : (choices[0] ?? 3),
                            });
                        }}
                    >
                        {cadences.map((cadence) => (
                            <option key={cadence.value} value={cadence.value}>
                                {cadence.label}
                            </option>
                        ))}
                    </Select>
                    <InputError message={form.errors.cadence} className="mt-1" />
                </label>

                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-gray-700">Runs for</span>
                    <Select
                        value={String(form.data.duration_months)}
                        onChange={(e) => form.setData('duration_months', Number(e.target.value))}
                    >
                        {durationChoices.map((months) => (
                            <option key={months} value={months}>
                                {months % 12 === 0
                                    ? `${months / 12} year${months === 12 ? '' : 's'}`
                                    : `${months} months`}
                            </option>
                        ))}
                    </Select>
                    <InputError message={form.errors.duration_months} className="mt-1" />
                </label>
            </div>

            {/* The whole term in one line, which is what an admin is actually
                deciding. It replaces a paragraph under each field. */}
            <p className="rounded-xl bg-brand-50 px-4 py-3 text-sm font-bold text-brand-800">
                {payments} payment{payments === 1 ? '' : 's'}
                {perPayment !== null && (
                    <> of {naira.format(perPayment)} on a {naira.format(trial)} order</>
                )}
            </p>

            <label className="block">
                <span className="mb-1.5 block text-xs font-bold text-gray-700">
                    Minimum order <span className="font-normal text-gray-400">0 for any</span>
                </span>
                <MoneyInput
                    min={0}
                    value={form.data.min_target_naira}
                    onChange={(value) => form.setData('min_target_naira', value === '' ? 0 : value)}
                />
                <InputError message={form.errors.min_target_naira} className="mt-1" />
            </label>

            {/* Both deadlines behind one toggle. They have sensible defaults
                and most terms never touch them, but they decide whether a
                customer can lose a plan — so they are hidden, not removed, and
                the summary line says what they currently do. */}
            <button
                type="button"
                onClick={() => setShowDeadlines((open) => !open)}
                className="flex w-full items-center justify-between rounded-xl bg-gray-50 px-4 py-2.5 text-left text-sm font-semibold text-gray-700 transition hover:bg-gray-100"
            >
                <span>
                    Deadlines
                    <span className="ml-1.5 font-normal text-gray-400">
                        {form.data.first_payment_due_days === 0
                            ? 'pay at checkout'
                            : `${form.data.first_payment_due_days} days to start`}
                        {form.data.missed_payments_allowed === 0
                            ? ', never closed'
                            : `, closes after ${form.data.missed_payments_allowed} missed`}
                    </span>
                </span>
                {showDeadlines ? (
                    <ChevronUp className="h-4 w-4 shrink-0" />
                ) : (
                    <ChevronDown className="h-4 w-4 shrink-0" />
                )}
            </button>

            {showDeadlines && (
                <div className="space-y-3 rounded-xl border border-gray-100 p-4">
                    <label className="block">
                        <span className="mb-1.5 block text-[11px] font-semibold text-gray-500">
                            First payment due
                        </span>
                        <Select
                            value={String(form.data.first_payment_due_days)}
                            onChange={(e) =>
                                form.setData('first_payment_due_days', Number(e.target.value))
                            }
                        >
                            <option value="0">At checkout</option>
                            {[1, 2, 3, 5, 7, 14, 30].map((days) => (
                                <option key={days} value={days}>
                                    Within {days} day{days === 1 ? '' : 's'}
                                </option>
                            ))}
                        </Select>
                        {/* The one line worth keeping: a plan can be lost here,
                            and "0 days" does not say so on its own. */}
                        <p className="mt-1 text-[11px] leading-relaxed text-gray-400">
                            {form.data.first_payment_due_days === 0
                                ? 'Charged before the plan starts.'
                                : 'Unpaid by then and the plan is cancelled. Anything paid becomes credit.'}
                        </p>
                        <InputError message={form.errors.first_payment_due_days} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-[11px] font-semibold text-gray-500">
                            Missed payments allowed
                        </span>
                        <Select
                            value={String(form.data.missed_payments_allowed)}
                            onChange={(e) =>
                                form.setData('missed_payments_allowed', Number(e.target.value))
                            }
                        >
                            <option value="0">Never close for inactivity</option>
                            {[1, 2, 3, 4, 6, 8, 12].map((count) => (
                                <option key={count} value={count}>
                                    Close after {count} missed
                                </option>
                            ))}
                        </Select>
                        <p className="mt-1 text-[11px] leading-relaxed text-gray-400">
                            The customer is warned first, and one payment clears it.
                        </p>
                        <InputError message={form.errors.missed_payments_allowed} className="mt-1" />
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
                <span className="text-sm text-gray-700">Offer this at checkout</span>
            </label>

            {/* Check the split against a real basket without storing anything. */}
            <div className="rounded-xl bg-gray-50 p-3.5">
                <label htmlFor="try-order" className="mb-1.5 block text-xs font-bold text-gray-700">
                    Check it against an order <span className="font-normal text-gray-400">optional</span>
                </label>
                <MoneyInput
                    id="try-order"
                    min={0}
                    placeholder="e.g. 20,000"
                    value={tryOrder === '' ? '' : Number(tryOrder)}
                    onChange={(value) => setTryOrder(value === '' ? '' : String(value))}
                />
                <p className="mt-1.5 text-xs text-gray-500">
                    {perPayment !== null ? (
                        <>
                            A {naira.format(trial)} order becomes{' '}
                            <strong className="text-gray-800">
                                {naira.format(perPayment)} per payment
                            </strong>{' '}
                            for {payments} payments.
                        </>
                    ) : (
                        'Nothing is saved here — it only shows how the split would fall.'
                    )}
                </p>
            </div>

            <div className="flex justify-end gap-2 pt-1">
                {onDone && (
                    <button
                        type="button"
                        onClick={onDone}
                        className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-500 transition hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                )}
                <button
                    type="submit"
                    disabled={form.processing}
                    className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                >
                    {term ? 'Save changes' : 'Add term'}
                </button>
            </div>
        </form>
    );
}
