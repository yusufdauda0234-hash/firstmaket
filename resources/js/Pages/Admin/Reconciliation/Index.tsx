import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import { Pagination } from '@/Components/ui/Pagination';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Paginated } from '@/Types';
import { Head, router, useForm } from '@inertiajs/react';
import { ChevronRight, FileSpreadsheet, Scale, Upload } from 'lucide-react';
import { useState } from 'react';

interface ImportRow {
    id: number;
    provider: string;
    status: string;
    itemsCount: number;
    unmatchedCount: number;
    importedBy: string | null;
    completedAt: string | null;
    createdAt: string;
}

interface Props {
    imports: Paginated<ImportRow>;
    [key: string]: unknown;
}

export default function ReconciliationIndex({ imports }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ settlement: '' });

    const submit = () => {
        form.post(route('admin.reconciliation.store'), { onSuccess: () => setOpen(false) });
    };

    return (
        <AdminLayout>
            <Head title="Reconciliation" />

            <PageHeader
                eyebrow="Finance"
                title="Settlement reconciliation"
                description="Match Paystack settlement batches against the internal ledger and flag every discrepancy."
                actions={
                    <Button onClick={() => setOpen(true)} className="active:scale-95">
                        <Upload className="mr-2 h-4 w-4" /> Import settlement
                    </Button>
                }
            />

            <Reveal>
                <Card className="overflow-hidden p-0">
                    {imports.data.length === 0 ? (
                        <div className="flex flex-col items-center px-6 py-16 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                                <Scale className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">No settlement imports yet</p>
                            <p className="mt-1 text-sm text-gray-500">
                                Import a Paystack settlement file to reconcile it against the ledger.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-gray-100">
                            {imports.data.map((row) => (
                                <button
                                    key={row.id}
                                    type="button"
                                    onClick={() => router.visit(route('admin.reconciliation.show', row.id))}
                                    className="group flex w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-brand-50/50"
                                >
                                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                                        <FileSpreadsheet className="h-5 w-5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold capitalize text-gray-900 group-hover:text-brand-700">
                                            {row.provider} settlement #{row.id}
                                        </p>
                                        <p className="text-sm text-gray-500">
                                            {row.itemsCount} lines · imported by {row.importedBy ?? 'system'} ·{' '}
                                            {row.completedAt ?? row.createdAt}
                                        </p>
                                    </div>
                                    {row.unmatchedCount > 0 ? (
                                        <span className="rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-600">
                                            {row.unmatchedCount} to review
                                        </span>
                                    ) : (
                                        <span className="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                            All matched
                                        </span>
                                    )}
                                    <ChevronRight className="h-4 w-4 shrink-0 text-gray-300 transition-transform group-hover:translate-x-1 group-hover:text-brand-500" />
                                </button>
                            ))}
                        </div>
                    )}
                </Card>
            </Reveal>

            <Pagination links={imports.links} />

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                icon={<Upload className="h-6 w-6" />}
                title="Import Paystack settlement"
                description="Paste the settlement lines as CSV: one reference,amount(₦) per line. A header row is optional."
                size="lg"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing || form.data.settlement.trim() === ''} className="active:scale-95">
                            {form.processing ? 'Reconciling…' : 'Reconcile'}
                        </Button>
                    </>
                }
            >
                <div>
                    <Label htmlFor="settlement">Settlement lines (CSV)</Label>
                    <textarea
                        id="settlement"
                        rows={8}
                        value={form.data.settlement}
                        onChange={(e) => form.setData('settlement', e.target.value)}
                        placeholder={'reference,amount\nFMW_01hxx...,5000\nFMW_01hyy...,20000'}
                        className="mt-1 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                    />
                    <InputError message={form.errors.settlement} />
                </div>
            </Modal>
        </AdminLayout>
    );
}
