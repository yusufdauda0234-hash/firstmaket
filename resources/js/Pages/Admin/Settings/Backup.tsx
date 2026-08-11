import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface TableRow {
    name: string;
    rowCount: number;
}

interface Props {
    tables: TableRow[];
    mysqldumpAvailable: boolean;
    [key: string]: unknown;
}

export default function Backup() {
    const { tables = [], mysqldumpAvailable } = usePage<Props>().props;
    const [selected, setSelected] = useState<string[]>([]);
    const [confirming, setConfirming] = useState(false);

    const totalRows = useMemo(() => tables.reduce((sum, t) => sum + t.rowCount, 0), [tables]);
    const selectedRows = useMemo(
        () => tables.filter((t) => selected.includes(t.name)).reduce((sum, t) => sum + t.rowCount, 0),
        [tables, selected],
    );

    const toggle = (name: string) =>
        setSelected((current) => (current.includes(name) ? current.filter((n) => n !== name) : [...current, name]));

    const toggleAll = () =>
        setSelected((current) => (current.length === tables.length ? [] : tables.map((t) => t.name)));

    const downloadUrl = (onlySelected: boolean) => {
        const params = new URLSearchParams();
        if (onlySelected) {
            selected.forEach((name) => params.append('tables[]', name));
        }
        const query = params.toString();
        return route('admin.settings.backup.download') + (query ? `?${query}` : '');
    };

    return (
        <AdminLayout>
            <Head title="Database backups" />
            <PageHeader
                title="Database backups"
                description="Download a raw SQL export, or wipe a table's data outright. There is no undo — this is the most powerful screen in the admin."
            />

            {!mysqldumpAvailable && (
                <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <span className="font-bold">mysqldump was not found on this server.</span>{' '}
                    Downloads will fail until it is installed on the app server.
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Button onClick={() => (window.location.href = downloadUrl(false))} disabled={!mysqldumpAvailable}>
                    Download full backup
                </Button>
                <Button
                    variant="secondary"
                    onClick={() => (window.location.href = downloadUrl(true))}
                    disabled={!mysqldumpAvailable || selected.length === 0}
                >
                    Download selected only ({selected.length})
                </Button>
                <button
                    type="button"
                    onClick={() => selected.length > 0 && setConfirming(true)}
                    disabled={selected.length === 0}
                    className="ml-auto rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 disabled:pointer-events-none disabled:opacity-50"
                >
                    Delete selected tables' data ({selected.length})
                </button>
            </div>

            <Card className="overflow-hidden !p-0">
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                    <label className="flex items-center gap-2 text-sm font-medium text-gray-700">
                        <input
                            type="checkbox"
                            checked={tables.length > 0 && selected.length === tables.length}
                            onChange={toggleAll}
                            className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                        />
                        Select all ({tables.length} tables, {totalRows.toLocaleString()} rows total)
                    </label>
                    {selected.length > 0 && (
                        <span className="text-xs font-semibold text-gray-500">
                            {selected.length} selected · {selectedRows.toLocaleString()} rows
                        </span>
                    )}
                </div>
                <div className="max-h-[60vh] divide-y divide-gray-50 overflow-y-auto">
                    {tables.map((table) => (
                        <label
                            key={table.name}
                            className="flex cursor-pointer items-center justify-between gap-3 px-5 py-2.5 text-sm hover:bg-gray-50"
                        >
                            <span className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    checked={selected.includes(table.name)}
                                    onChange={() => toggle(table.name)}
                                    className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                />
                                <span className="font-mono text-gray-800">{table.name}</span>
                            </span>
                            <span className="text-xs text-gray-400">{table.rowCount.toLocaleString()} rows</span>
                        </label>
                    ))}
                </div>
            </Card>

            {confirming && (
                <ConfirmTruncate
                    tables={tables.filter((t) => selected.includes(t.name))}
                    onClose={() => setConfirming(false)}
                    onDone={() => {
                        setConfirming(false);
                        setSelected([]);
                    }}
                />
            )}
        </AdminLayout>
    );
}

function ConfirmTruncate({
    tables,
    onClose,
    onDone,
}: {
    tables: TableRow[];
    onClose: () => void;
    onDone: () => void;
}) {
    const form = useForm({
        tables: tables.map((t) => t.name),
        confirm: '',
    });
    const rowTotal = tables.reduce((sum, t) => sum + t.rowCount, 0);

    return (
        <Modal
            open
            onClose={onClose}
            title="This cannot be undone"
            description={`You are about to permanently delete all ${rowTotal.toLocaleString()} rows from ${tables.length} table(s). Download a backup first if you might need this data again.`}
            size="sm"
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={form.processing || form.data.confirm !== 'DELETE'}
                        onClick={() =>
                            form.post(route('admin.settings.backup.truncate'), {
                                preserveScroll: true,
                                onSuccess: onDone,
                            })
                        }
                        className="rounded-full bg-red-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-red-700 active:scale-95 disabled:pointer-events-none disabled:opacity-50"
                    >
                        Delete permanently
                    </button>
                </>
            }
        >
            <div className="max-h-40 overflow-y-auto rounded-lg border border-gray-100 bg-gray-50 p-3 font-mono text-xs text-gray-600">
                {tables.map((t) => (
                    <div key={t.name}>
                        {t.name} — {t.rowCount.toLocaleString()} rows
                    </div>
                ))}
            </div>
            <label className="mt-4 block">
                <span className="mb-1 block text-xs font-semibold text-gray-600">
                    Type DELETE to confirm
                </span>
                <Input
                    value={form.data.confirm}
                    onChange={(event) => form.setData('confirm', event.target.value)}
                    autoComplete="off"
                />
            </label>
        </Modal>
    );
}
