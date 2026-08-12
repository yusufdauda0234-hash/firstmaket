import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination, PaginationLink } from '@/Components/ui/Pagination';
import { Select } from '@/Components/ui/Select';
import { Textarea } from '@/Components/ui/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Paperclip, Plus, Wallet, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ExpenseRow {
    uuid: string;
    reference: string;
    category: string;
    categoryLabel: string;
    description: string;
    payee: string | null;
    amountKobo: number;
    incurredOn: string;
    paymentMethod: string | null;
    note: string | null;
    hasReceipt: boolean;
    status: string;
    statusLabel: string;
    recordedBy: string;
    approvedBy: string | null;
    decisionNote: string | null;
    canDecide: boolean;
}

interface Props {
    expenses: { data: ExpenseRow[]; links: PaginationLink[]; total: number };
    filters: { category: string; status: string; from: string; to: string };
    categories: { value: string; label: string }[];
    summary: {
        totalKobo: number;
        approvedKobo: number;
        byCategory: { category: string; label: string; totalKobo: number; count: number }[];
        byMonth: { month: string; totalKobo: number }[];
    };
    canApprove: boolean;
    [key: string]: unknown;
}

const STATUS_TONE: Record<string, 'neutral' | 'success' | 'warning' | 'danger'> = {
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
};

/**
 * What the business spends.
 *
 * Two figures at the top rather than one: total recorded and total approved.
 * They differ by whatever is still waiting on somebody, and collapsing them
 * into a single number would let unreviewed claims quietly become "what we
 * spent".
 */
export default function Expenses() {
    const { expenses, filters, categories, summary, canApprove } = usePage<Props>().props;
    const [adding, setAdding] = useState(false);

    const form = useForm<{
        category: string;
        description: string;
        payee: string;
        amount_naira: string;
        incurred_on: string;
        payment_method: string;
        note: string;
        receipt: File | null;
    }>({
        category: 'other',
        description: '',
        payee: '',
        amount_naira: '',
        incurred_on: new Date().toISOString().slice(0, 10),
        payment_method: 'transfer',
        note: '',
        receipt: null,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.expenses.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const applyFilter = (next: Partial<Props['filters']>) => {
        router.get(route('admin.expenses.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    };

    const decide = (expense: ExpenseRow, status: 'approved' | 'rejected') => {
        const note =
            status === 'rejected'
                ? window.prompt(`Why is ${expense.reference} being rejected?`)
                : null;

        if (status === 'rejected' && note === null) {
            return;
        }

        router.post(
            route('admin.expenses.decision', expense.uuid),
            { status, note },
            { preserveScroll: true },
        );
    };

    const pendingKobo = summary.totalKobo - summary.approvedKobo;
    const largestMonth = Math.max(1, ...summary.byMonth.map((month) => month.totalKobo));

    return (
        <AdminLayout>
            <Head title="Expenses" />
            <PageHeader
                eyebrow="Finance"
                title="Expenses"
                description="What the business spends, where it goes, and what is still waiting on a signature."
                actions={
                    <Button type="button" onClick={() => setAdding((open) => !open)}>
                        <Plus className="mr-1.5 h-4 w-4" /> Record expense
                    </Button>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <Stat label="Recorded" value={formatNairaFromKobo(summary.totalKobo)} hint="Everything except rejected" />
                <Stat label="Approved" value={formatNairaFromKobo(summary.approvedKobo)} hint="Signed off" tone="text-green-700" />
                <Stat
                    label="Awaiting approval"
                    value={formatNairaFromKobo(pendingKobo)}
                    hint="Not yet counted as spend"
                    tone={pendingKobo > 0 ? 'text-amber-700' : undefined}
                />
            </div>

            {adding && (
                <Card className="mb-6 p-5">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <Label htmlFor="description">What was it for</Label>
                            <Input
                                id="description"
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                                placeholder="Diesel for the Ikeja generator, July"
                            />
                            <InputError message={form.errors.description} />
                        </div>

                        <div>
                            <Label htmlFor="category">Category</Label>
                            <Select
                                id="category"
                                value={form.data.category}
                                onChange={(event) => form.setData('category', event.target.value)}
                            >
                                {categories.map((category) => (
                                    <option key={category.value} value={category.value}>
                                        {category.label}
                                    </option>
                                ))}
                            </Select>
                            <InputError message={form.errors.category} />
                        </div>

                        <div>
                            <Label htmlFor="amount">Amount (₦)</Label>
                            <Input
                                id="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={form.data.amount_naira}
                                onChange={(event) => form.setData('amount_naira', event.target.value)}
                                placeholder="45000.00"
                            />
                            <InputError message={form.errors.amount_naira} />
                        </div>

                        <div>
                            <Label htmlFor="incurred_on">Date spent</Label>
                            <Input
                                id="incurred_on"
                                type="date"
                                max={new Date().toISOString().slice(0, 10)}
                                value={form.data.incurred_on}
                                onChange={(event) => form.setData('incurred_on', event.target.value)}
                            />
                            <InputError message={form.errors.incurred_on} />
                        </div>

                        <div>
                            <Label htmlFor="payee">Paid to</Label>
                            <Input
                                id="payee"
                                value={form.data.payee}
                                onChange={(event) => form.setData('payee', event.target.value)}
                                placeholder="Total Filling Station"
                            />
                            <InputError message={form.errors.payee} />
                        </div>

                        <div>
                            <Label htmlFor="payment_method">How it was paid</Label>
                            <Select
                                id="payment_method"
                                value={form.data.payment_method}
                                onChange={(event) => form.setData('payment_method', event.target.value)}
                            >
                                <option value="transfer">Bank transfer</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="cheque">Cheque</option>
                                <option value="direct_debit">Direct debit</option>
                            </Select>
                            <InputError message={form.errors.payment_method} />
                        </div>

                        <div>
                            <Label htmlFor="receipt">Receipt (optional)</Label>
                            <input
                                id="receipt"
                                type="file"
                                accept=".jpg,.jpeg,.png,.pdf"
                                onChange={(event) => form.setData('receipt', event.target.files?.[0] ?? null)}
                                className="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700"
                            />
                            <InputError message={form.errors.receipt} />
                        </div>

                        <div className="sm:col-span-2">
                            <Label htmlFor="note">Note (optional)</Label>
                            <Textarea
                                id="note"
                                rows={2}
                                value={form.data.note}
                                onChange={(event) => form.setData('note', event.target.value)}
                            />
                            <InputError message={form.errors.note} />
                        </div>

                        <div className="flex items-center gap-2 sm:col-span-2">
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Record expense'}
                            </Button>
                            <button
                                type="button"
                                onClick={() => setAdding(false)}
                                className="text-sm font-medium text-gray-500 hover:text-gray-800"
                            >
                                Cancel
                            </button>
                            <p className="ml-auto text-xs text-gray-500">
                                Recorded expenses wait for approval — somebody other than you signs them off.
                            </p>
                        </div>
                    </form>
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="p-5 lg:col-span-1">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Where it goes</h2>
                    {summary.byCategory.length === 0 ? (
                        <p className="mt-4 text-sm text-gray-500">Nothing recorded in this period.</p>
                    ) : (
                        <ul className="mt-4 space-y-3">
                            {summary.byCategory.map((row) => (
                                <li key={row.category}>
                                    <div className="flex items-baseline justify-between text-sm">
                                        <span className="text-gray-700">{row.label}</span>
                                        <span className="font-medium tabular-nums text-gray-900">
                                            {formatNairaFromKobo(row.totalKobo)}
                                        </span>
                                    </div>
                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100">
                                        <div
                                            className="h-full rounded-full bg-brand-500"
                                            style={{
                                                width: `${Math.round((row.totalKobo / Math.max(1, summary.totalKobo)) * 100)}%`,
                                            }}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {summary.byMonth.length > 1 && (
                        <>
                            <h2 className="mt-6 text-sm font-semibold uppercase tracking-wide text-gray-500">By month</h2>
                            <div className="mt-3 flex h-24 items-end gap-1">
                                {summary.byMonth.map((month) => (
                                    <div
                                        key={month.month}
                                        className="group relative flex-1 rounded-t bg-brand-200 transition hover:bg-brand-400"
                                        style={{ height: `${Math.max(4, (month.totalKobo / largestMonth) * 100)}%` }}
                                        title={`${month.month}: ${formatNairaFromKobo(month.totalKobo)}`}
                                    />
                                ))}
                            </div>
                            <p className="mt-1 flex justify-between text-[10px] text-gray-400">
                                <span>{summary.byMonth[0].month}</span>
                                <span>{summary.byMonth[summary.byMonth.length - 1].month}</span>
                            </p>
                        </>
                    )}
                </Card>

                <Card className="overflow-hidden lg:col-span-2">
                    <div className="flex flex-wrap items-center gap-2 border-b border-gray-100 p-4">
                        <Select
                            value={filters.category}
                            onChange={(event) => applyFilter({ category: event.target.value })}
                            className="w-auto"
                        >
                            <option value="">All categories</option>
                            {categories.map((category) => (
                                <option key={category.value} value={category.value}>
                                    {category.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            value={filters.status}
                            onChange={(event) => applyFilter({ status: event.target.value })}
                            className="w-auto"
                        >
                            <option value="">Any status</option>
                            <option value="pending">Awaiting approval</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </Select>
                        <Input
                            type="date"
                            value={filters.from}
                            onChange={(event) => applyFilter({ from: event.target.value })}
                            className="w-auto"
                            aria-label="From"
                        />
                        <Input
                            type="date"
                            value={filters.to}
                            onChange={(event) => applyFilter({ to: event.target.value })}
                            className="w-auto"
                            aria-label="To"
                        />
                    </div>

                    {expenses.data.length === 0 ? (
                        <div className="p-10 text-center">
                            <Wallet className="mx-auto h-10 w-10 text-gray-300" />
                            <p className="mt-3 text-sm text-gray-600">No expenses match these filters.</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold">Date</th>
                                        <th className="px-4 py-3 font-semibold">What</th>
                                        <th className="px-4 py-3 text-right font-semibold">Amount</th>
                                        <th className="px-4 py-3 font-semibold">Status</th>
                                        {canApprove && <th className="px-4 py-3" />}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {expenses.data.map((expense) => (
                                        <tr key={expense.uuid} className="hover:bg-gray-50/60">
                                            <td className="whitespace-nowrap px-4 py-3 text-gray-600">
                                                {expense.incurredOn}
                                                <span className="block font-mono text-[10px] text-gray-400">
                                                    {expense.reference}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="font-medium text-gray-900">{expense.description}</span>
                                                <span className="mt-0.5 block text-xs text-gray-500">
                                                    {expense.categoryLabel}
                                                    {expense.payee && ` · ${expense.payee}`}
                                                    {expense.hasReceipt && (
                                                        <>
                                                            {' · '}
                                                            <a
                                                                href={route('admin.expenses.receipt', expense.uuid)}
                                                                className="inline-flex items-center gap-0.5 text-brand-700 hover:underline"
                                                            >
                                                                <Paperclip className="h-3 w-3" /> receipt
                                                            </a>
                                                        </>
                                                    )}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-gray-900">
                                                {formatNairaFromKobo(expense.amountKobo)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3">
                                                <Badge tone={STATUS_TONE[expense.status] ?? 'neutral'}>
                                                    {expense.statusLabel}
                                                </Badge>
                                                <span className="mt-0.5 block text-[10px] text-gray-400">
                                                    by {expense.recordedBy}
                                                    {expense.approvedBy && ` · ${expense.approvedBy}`}
                                                </span>
                                            </td>
                                            {canApprove && (
                                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                                    {expense.canDecide ? (
                                                        <div className="flex justify-end gap-1">
                                                            <button
                                                                type="button"
                                                                onClick={() => decide(expense, 'approved')}
                                                                aria-label={`Approve ${expense.reference}`}
                                                                className="rounded-full p-1.5 text-green-600 transition hover:bg-green-50"
                                                            >
                                                                <Check className="h-4 w-4" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => decide(expense, 'rejected')}
                                                                aria-label={`Reject ${expense.reference}`}
                                                                className="rounded-full p-1.5 text-red-600 transition hover:bg-red-50"
                                                            >
                                                                <X className="h-4 w-4" />
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs text-gray-300">—</span>
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <Pagination links={expenses.links} />
                </Card>
            </div>
        </AdminLayout>
    );
}

function Stat({ label, value, hint, tone }: { label: string; value: string; hint: string; tone?: string }) {
    return (
        <Card className="p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-bold tabular-nums ${tone ?? 'text-gray-900'}`}>{value}</p>
            <p className="mt-0.5 text-xs text-gray-400">{hint}</p>
        </Card>
    );
}
