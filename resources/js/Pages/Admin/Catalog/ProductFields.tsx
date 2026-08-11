import TemplatePicker, { Template } from '@/Components/domain/admin/TemplatePicker';
import DynamicFields, { AttributeField, AttributeValues } from '@/Components/domain/catalog/DynamicFields';
import BulkActionBar from '@/Components/ui/BulkActionBar';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import { Select } from '@/Components/ui/Select';
import { useRowSelection } from '@/Hooks/useRowSelection';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ListChecks, Lock, Pencil, Plus, Trash2, X } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

interface FieldRow {
    id: number;
    isBuiltIn: boolean;
    systemKey: string | null;
    categoryId: number | null;
    categoryLabel: string;
    key: string;
    label: string;
    type: string;
    typeLabel: string;
    options: string[];
    unit: string | null;
    helpText: string | null;
    placeholder: string | null;
    isRequired: boolean;
    isActive: boolean;
    sortOrder: number;
    usageCount: number;
}

interface FieldType {
    value: string;
    label: string;
    description: string;
    hasOptions: boolean;
}

interface Props {
    /** Ready-made settings an admin can apply in one click. */
    templates: Template[];
    attributes: FieldRow[];
    categories: { id: number; label: string }[];
    fieldTypes: FieldType[];
    errors: Record<string, string>;
    [key: string]: unknown;
}

/**
 * The product-form builder.
 *
 * Staff decide what each category's products must describe — colour and
 * storage for phones, material for furniture, a demo link where it helps —
 * and the vendor form renders itself from these rows. No migration, no
 * deploy, no developer.
 */
export default function AdminProductFields() {
    const { attributes, categories, fieldTypes, errors, templates = [] } = usePage<Props>().props;
    const [editing, setEditing] = useState<FieldRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<FieldRow | null>(null);
    const [filter, setFilter] = useState<string>('all');

    const form = useForm({
        category_id: '' as number | '',
        label: '',
        type: 'text',
        options: [] as string[],
        unit: '',
        is_required: false,
        is_active: true,
    });

    const [optionDraft, setOptionDraft] = useState('');

    const selectedType = fieldTypes.find((t) => t.value === form.data.type);
    // Built-ins map to real product columns, so only their wording is
    // editable — the server ignores anything else, and a form that pretends
    // otherwise is a lie.
    const editingBuiltIn = editing?.isBuiltIn ?? false;

    const visible = useMemo(
        () =>
            filter === 'all'
                ? attributes
                : filter === 'global'
                  ? attributes.filter((a) => a.categoryId === null)
                  : attributes.filter((a) => String(a.categoryId) === filter),
        [attributes, filter],
    );

    const selection = useRowSelection(visible.filter((f) => !f.isBuiltIn).map((f) => String(f.id)));
    const bulk = useForm<{ action: string; ids: number[] }>({ action: 'activate', ids: [] });

    function runBulk(action: 'activate' | 'deactivate') {
        bulk.transform(() => ({ action, ids: selection.ids.map(Number) }));
        bulk.post(route('admin.catalog.fields.bulk'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }

    const openCreate = () => {
        form.setData({
            category_id: '',
            label: '',
            type: 'text',
            options: [],
            unit: '',
            is_required: false,
            is_active: true,
        });
        form.clearErrors();
        setOptionDraft('');
        setCreating(true);
    };

    const openEdit = (field: FieldRow) => {
        form.setData({
            category_id: field.categoryId ?? '',
            label: field.label,
            type: field.type,
            options: field.options,
            unit: field.unit ?? '',
            is_required: field.isRequired,
            is_active: field.isActive,
        });
        form.clearErrors();
        setOptionDraft('');
        setEditing(field);
    };

    const close = () => {
        setCreating(false);
        setEditing(null);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            form.put(route('admin.catalog.fields.update', editing.id), { onSuccess: close });
        } else {
            form.post(route('admin.catalog.fields.store'), { onSuccess: close });
        }
    };

    const addOption = () => {
        const value = optionDraft.trim();
        if (value === '' || form.data.options.includes(value)) return;
        form.setData('options', [...form.data.options, value]);
        setOptionDraft('');
    };

    // The same renderer the vendor sees, fed the field being edited — so the
    // person defining it sees exactly what they are creating.
    const previewField: AttributeField = {
        id: -1,
        key: 'preview',
        label: form.data.label || 'Your field',
        type: (form.data.type as AttributeField['type']) ?? 'text',
        options: form.data.options,
        unit: form.data.unit || null,
        required: form.data.is_required,
        helpText: null,
        placeholder: null,
    };
    const [previewValue, setPreviewValue] = useState<AttributeValues>({});

    return (
        <AdminLayout>
            <Head title="Product form fields" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight text-gray-900">
                        <ListChecks className="h-6 w-6 text-brand-600" /> Product form fields
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm text-gray-500">
                        What vendors are asked to fill in when listing. Fields set on a category apply to
                        every category nested under it; a field with no category applies everywhere.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <TemplatePicker
                        templates={templates}
                        action={route('admin.catalog.fields.template')}
                        noun="listing fields"
                        empty={attributes.length === 0}
                    />
                    <button
                        type="button"
                        onClick={openCreate}
                        className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                    >
                        <Plus className="h-4 w-4" /> New field
                    </button>
                </div>
            </div>


            <InputError message={errors.field} className="mb-4" />

            <div className="mb-4 max-w-xs">
                <Select value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter by category">
                    <option value="all">All fields</option>
                    <option value="global">Every category</option>
                    {categories.map((category) => (
                        <option key={category.id} value={String(category.id)}>
                            {category.label}
                        </option>
                    ))}
                </Select>
            </div>

            <div className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                {visible.length === 0 ? (
                    <p className="px-5 py-16 text-center text-sm text-gray-400">
                        No fields here yet. Vendors see only the standard name, description, price and
                        stock until you add some.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[860px] text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th className="w-10 py-3 pl-5 pr-2">
                                        <RowCheckbox
                                            checked={selection.allSelected}
                                            indeterminate={selection.someSelected}
                                            onChange={selection.toggleAll}
                                            label="Select all custom fields"
                                        />
                                    </th>
                                    <th className="w-12 px-2 py-3 font-semibold">S/N</th>
                                    <th className="px-5 py-3 font-semibold">Field</th>
                                    <th className="px-4 py-3 font-semibold">Applies to</th>
                                    <th className="px-4 py-3 font-semibold">Type</th>
                                    <th className="px-4 py-3 font-semibold">Required</th>
                                    <th className="px-4 py-3 font-semibold">In use</th>
                                    <th className="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {visible.map((field, index) => (
                                    <tr
                                        key={field.id}
                                        className={`transition-colors hover:bg-slate-50/60 ${
                                            selection.isSelected(String(field.id)) ? 'bg-brand-50/70' : ''
                                        }`}
                                    >
                                        <td className="py-3.5 pl-5 pr-2">
                                            {field.isBuiltIn ? (
                                                <Lock
                                                    className="h-4 w-4 text-gray-300"
                                                    aria-label="Built-in field, always asked"
                                                />
                                            ) : (
                                                <RowCheckbox
                                                    checked={selection.isSelected(String(field.id))}
                                                    onChange={() => selection.toggle(String(field.id))}
                                                    label={`Select ${field.label}`}
                                                />
                                            )}
                                        </td>
                                        <td className="px-2 py-3.5 text-xs tabular-nums text-gray-400">
                                            {index + 1}
                                        </td>
                                        <td className="px-5 py-3">
                                            <span className="font-semibold text-gray-900">{field.label}</span>
                                            {field.isBuiltIn && (
                                                <span className="ml-2 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-bold text-brand-700">
                                                    Built in
                                                </span>
                                            )}
                                            {!field.isActive && (
                                                <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-500">
                                                    Off
                                                </span>
                                            )}
                                            <span className="mt-0.5 block text-xs text-gray-400">
                                                {field.key}
                                                {field.options.length > 0 &&
                                                    ` · ${field.options.slice(0, 4).join(', ')}${
                                                        field.options.length > 4 ? '…' : ''
                                                    }`}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">{field.categoryLabel}</td>
                                        <td className="px-4 py-3 text-gray-600">{field.typeLabel}</td>
                                        <td className="px-4 py-3">
                                            {field.isRequired ? (
                                                <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-700">
                                                    Required
                                                </span>
                                            ) : (
                                                <span className="text-xs text-gray-400">Optional</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums text-gray-500">
                                            {field.usageCount}
                                        </td>
                                        <td className="px-5 py-3">
                                            <span className="flex items-center justify-end gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(field)}
                                                    title="Edit"
                                                    className="rounded-lg p-1.5 text-gray-400 transition hover:bg-slate-100 hover:text-gray-700"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                {!field.isBuiltIn && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setConfirmDelete(field)}
                                                        title="Delete"
                                                        className="rounded-lg p-1.5 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <BulkActionBar
                count={selection.count}
                noun="field"
                processing={bulk.processing}
                onClear={selection.clear}
                actions={[
                    { label: 'Switch on', tone: 'primary', run: () => runBulk('activate') },
                    { label: 'Switch off', tone: 'neutral', run: () => runBulk('deactivate') },
                ]}
            />

            {/* ── Create / edit ── */}
            <Modal
                open={creating || editing !== null}
                onClose={close}
                title={editing ? `Edit “${editing.label}”` : 'New product field'}
                size="xl"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="category_id" className="mb-1.5 block text-xs font-bold text-gray-700">
                                Applies to
                            </label>
                            <Select
                                id="category_id"
                                disabled={editingBuiltIn}
                                value={form.data.category_id === '' ? '' : String(form.data.category_id)}
                                onChange={(e) =>
                                    form.setData(
                                        'category_id',
                                        e.target.value === '' ? '' : Number(e.target.value),
                                    )
                                }
                            >
                                <option value="">Every category</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.label}
                                    </option>
                                ))}
                            </Select>
                            <p className="mt-1 text-xs text-gray-400">
                                Sub-categories inherit this automatically.
                            </p>
                            <InputError message={form.errors.category_id} className="mt-1" />
                        </div>

                        <div>
                            <label htmlFor="label" className="mb-1.5 block text-xs font-bold text-gray-700">
                                Label the vendor sees
                            </label>
                            <Input
                                id="label"
                                value={form.data.label}
                                onChange={(e) => form.setData('label', e.target.value)}
                                placeholder="e.g. Colour"
                                autoFocus
                            />
                            <InputError message={form.errors.label} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <label htmlFor="type" className="mb-1.5 block text-xs font-bold text-gray-700">
                            Kind of field
                        </label>
                        <Select
                            id="type"
                            value={form.data.type}
                            disabled={editingBuiltIn || (editing !== null && editing.usageCount > 0)}
                            onChange={(e) => form.setData('type', e.target.value)}
                        >
                            {fieldTypes.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </Select>
                        <p className="mt-1 text-xs text-gray-400">
                            {editing !== null && editing.usageCount > 0
                                ? 'Locked — vendors have already answered this field. Switch it off and add a replacement to change the type.'
                                : selectedType?.description}
                        </p>
                        <InputError message={form.errors.type} className="mt-1" />
                    </div>

                    {selectedType?.hasOptions && (
                        <div>
                            <span className="mb-1.5 block text-xs font-bold text-gray-700">Choices</span>
                            <div className="flex gap-2">
                                <Input
                                    value={optionDraft}
                                    onChange={(e) => setOptionDraft(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            addOption();
                                        }
                                    }}
                                    placeholder="Type a choice and press Enter"
                                />
                                <button
                                    type="button"
                                    onClick={addOption}
                                    className="shrink-0 rounded-lg bg-gray-900 px-4 text-xs font-bold text-white transition hover:bg-gray-800"
                                >
                                    Add
                                </button>
                            </div>
                            {form.data.options.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {form.data.options.map((option) => (
                                        <span
                                            key={option}
                                            className="inline-flex items-center gap-1 rounded-full bg-slate-100 py-1 pl-3 pr-1.5 text-xs font-semibold text-gray-700"
                                        >
                                            {option}
                                            <button
                                                type="button"
                                                aria-label={`Remove ${option}`}
                                                onClick={() =>
                                                    form.setData(
                                                        'options',
                                                        form.data.options.filter((o) => o !== option),
                                                    )
                                                }
                                                className="rounded-full p-0.5 text-gray-400 transition hover:bg-white hover:text-red-600"
                                            >
                                                <X className="h-3 w-3" />
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            )}
                            <InputError message={form.errors.options} className="mt-1" />
                        </div>
                    )}

                    {/* Only on a number, where it is the difference between
                        "5" and "5 kg". Under a dropdown it is noise, and the
                        placeholder, hint and sort-order boxes that used to sit
                        here were four decisions nobody was making. */}
                    {form.data.type === 'number' && (
                        <div>
                            <label htmlFor="unit" className="mb-1.5 block text-xs font-bold text-gray-700">
                                Unit <span className="font-normal text-gray-400">optional</span>
                            </label>
                            <Input
                                id="unit"
                                value={form.data.unit}
                                onChange={(e) => form.setData('unit', e.target.value)}
                                placeholder="kg, W, inches"
                            />
                        </div>
                    )}

                    <div className="flex flex-wrap gap-5">
                        <label className="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_required}
                                disabled={editingBuiltIn}
                                onChange={(e) => form.setData('is_required', e.target.checked)}
                                className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                            />
                            Vendors must fill this in
                        </label>
                        <label className="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                                className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                            />
                            Show on the form
                        </label>
                    </div>

                    {/* Rendered by the very component the vendor form uses. */}
                    <div className="rounded-xl border border-dashed border-gray-300 bg-slate-50/60 p-4">
                        <p className="mb-3 text-xs font-bold uppercase tracking-wide text-gray-400">
                            How the vendor will see it
                        </p>
                        <DynamicFields
                            fields={[previewField]}
                            values={previewValue}
                            errors={{}}
                            onChange={(key, value) => setPreviewValue({ [key]: value })}
                        />
                    </div>

                    <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                        <button
                            type="button"
                            onClick={close}
                            className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing || form.data.label.trim() === ''}
                            className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:bg-gray-200 disabled:text-gray-400"
                        >
                            {editing ? 'Save changes' : 'Add field'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* ── Delete ── */}
            <Modal
                open={confirmDelete !== null}
                onClose={() => setConfirmDelete(null)}
                title={`Delete “${confirmDelete?.label}”?`}
                size="sm"
                footer={
                    <>
                        <button
                            type="button"
                            onClick={() => setConfirmDelete(null)}
                            className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                        >
                            Keep it
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                if (!confirmDelete) return;
                                form.delete(route('admin.catalog.fields.destroy', confirmDelete.id), {
                                    onFinish: () => setConfirmDelete(null),
                                });
                            }}
                            className="rounded-full bg-red-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-red-700 active:scale-95"
                        >
                            Delete
                        </button>
                    </>
                }
            >
                <p className="text-sm text-gray-600">
                    {confirmDelete && confirmDelete.usageCount > 0 ? (
                        <>
                            <strong>{confirmDelete.usageCount} product(s)</strong> have answers saved
                            against this field. Deleting it would lose them — switch it off instead.
                        </>
                    ) : (
                        'This field will disappear from the vendor form. This cannot be undone.'
                    )}
                </p>
            </Modal>
        </AdminLayout>
    );
}
