import BulkActionBar from '@/Components/ui/BulkActionBar';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import RowCheckbox from '@/Components/ui/RowCheckbox';
import { Select } from '@/Components/ui/Select';
import { useRowSelection } from '@/Hooks/useRowSelection';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CornerDownRight, FolderTree, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface CategoryRow {
    id: number;
    parentId: number | null;
    name: string;
    slug: string;
    description: string | null;
    isActive: boolean;
    depth: number;
    productCount: number;
    childCount: number;
}

interface Props {
    categories: CategoryRow[];
    maxDepth: number;
    errors: Record<string, string>;
    [key: string]: unknown;
}

/**
 * The catalogue tree.
 *
 * Rendered as one indented row per category rather than a collapsing tree:
 * at three levels the whole catalogue fits on a screen, and a flat table is
 * far easier to scan for "where does this product type live" than something
 * that has to be expanded branch by branch.
 */
export default function AdminCategories() {
    const { categories, maxDepth, errors } = usePage<Props>().props;

    const selection = useRowSelection(categories.map((c) => String(c.id)));
    const bulk = useForm<{ action: string; ids: number[] }>({ action: 'activate', ids: [] });

    function runBulk(action: 'activate' | 'deactivate') {
        bulk.transform(() => ({ action, ids: selection.ids.map(Number) }));
        bulk.post(route('admin.catalog.categories.bulk'), {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        });
    }
    const [editing, setEditing] = useState<CategoryRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<CategoryRow | null>(null);

    const form = useForm({
        parent_id: '' as number | '',
        name: '',
        description: '',
        is_active: true,
    });

    const openCreate = (parentId: number | null = null) => {
        form.setData({
            parent_id: parentId ?? '',
            name: '',
            description: '',
            is_active: true,
        });
        form.clearErrors();
        setCreating(true);
    };

    const openEdit = (category: CategoryRow) => {
        form.setData({
            parent_id: category.parentId ?? '',
            name: category.name,
            description: category.description ?? '',
            is_active: category.isActive,
        });
        form.clearErrors();
        setEditing(category);
    };

    const close = () => {
        setCreating(false);
        setEditing(null);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            form.put(route('admin.catalog.categories.update', editing.id), { onSuccess: close });
        } else {
            form.post(route('admin.catalog.categories.store'), { onSuccess: close });
        }
    };

    // A category can only be a parent if nesting under it stays within the
    // depth cap, and it can never be its own parent.
    const parentOptions = categories.filter(
        (candidate) => candidate.depth < maxDepth && candidate.id !== editing?.id,
    );

    return (
        <AdminLayout>
            <Head title="Categories" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight text-gray-900">
                        <FolderTree className="h-6 w-6 text-brand-600" /> Categories
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        What vendors can list under, and how shoppers browse. Up to {maxDepth + 1} levels
                        deep.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => openCreate(null)}
                    className="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95"
                >
                    <Plus className="h-4 w-4" /> Add category
                </button>
            </div>

            {/* No inline success banner: AdminLayout already raises a toast
                for flash.success, so a box here said the same thing twice and
                then sat on the page until the next navigation. Errors stay
                inline — an error should not vanish on a timer. */}
            <InputError message={errors.category} className="mb-4" />

            <div className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                {categories.length === 0 ? (
                    <p className="px-5 py-16 text-center text-sm text-gray-400">
                        No categories yet. Add the first one to open the marketplace for listings.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[600px] text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th className="w-10 py-3 pl-5 pr-2">
                                        <RowCheckbox
                                            checked={selection.allSelected}
                                            indeterminate={selection.someSelected}
                                            onChange={selection.toggleAll}
                                            label="Select all categories"
                                        />
                                    </th>
                                    <th className="px-5 py-3 font-semibold">Category</th>
                                    <th className="w-24 px-4 py-3 text-right font-semibold">Products</th>
                                    <th className="w-24 px-4 py-3 font-semibold">Status</th>
                                    <th className="w-28 px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {categories.map((category) => (
                                    <tr
                                        key={category.id}
                                        className={`transition-colors hover:bg-slate-50/60 ${
                                            selection.isSelected(String(category.id)) ? 'bg-brand-50/70' : ''
                                        }`}
                                    >
                                        <td className="py-3.5 pl-5 pr-2">
                                            <RowCheckbox
                                                checked={selection.isSelected(String(category.id))}
                                                onChange={() => selection.toggle(String(category.id))}
                                                label={`Select ${category.name}`}
                                            />
                                        </td>
                                        <td className="px-5 py-2.5">
                                            {/* One line per category. The
                                                description used to print in
                                                full underneath, which turned
                                                every row into a three-line
                                                paragraph and buried the tree
                                                structure the indent is there
                                                to show. It lives in the edit
                                                dialog and on the storefront;
                                                here it is a tooltip. */}
                                            <span
                                                className="flex min-w-0 items-center gap-1.5"
                                                style={{ paddingLeft: `${category.depth * 20}px` }}
                                                title={category.description ?? undefined}
                                            >
                                                {category.depth > 0 && (
                                                    <CornerDownRight className="h-3.5 w-3.5 shrink-0 text-gray-300" />
                                                )}
                                                <span className="truncate font-semibold text-gray-900">
                                                    {category.name}
                                                </span>
                                                <span className="shrink-0 text-xs text-gray-300">
                                                    /{category.slug}
                                                </span>
                                                {category.childCount > 0 && (
                                                    <span className="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-bold text-gray-500">
                                                        {category.childCount} sub
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums text-gray-600">
                                            {category.productCount === 0 ? (
                                                <span className="text-gray-300">—</span>
                                            ) : (
                                                category.productCount
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                                                    category.isActive
                                                        ? 'bg-emerald-50 text-emerald-700'
                                                        : 'bg-gray-100 text-gray-500'
                                                }`}
                                            >
                                                {category.isActive ? 'Active' : 'Hidden'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-2.5">
                                            <span className="flex items-center justify-end gap-1">
                                                {category.depth < maxDepth && (
                                                    <button
                                                        type="button"
                                                        onClick={() => openCreate(category.id)}
                                                        title="Add a sub-category"
                                                        className="rounded-lg p-1.5 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                                                    >
                                                        <Plus className="h-4 w-4" />
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(category)}
                                                    title="Edit"
                                                    className="rounded-lg p-1.5 text-gray-400 transition hover:bg-slate-100 hover:text-gray-700"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setConfirmDelete(category)}
                                                    title="Delete"
                                                    className="rounded-lg p-1.5 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
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
                noun="category"
                plural="categories"
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
                title={editing ? `Edit “${editing.name}”` : 'New category'}
                size="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label htmlFor="parent_id" className="mb-1.5 block text-xs font-bold text-gray-700">
                            Sits under
                        </label>
                        <Select
                            id="parent_id"
                            value={form.data.parent_id === '' ? '' : String(form.data.parent_id)}
                            onChange={(e) =>
                                form.setData('parent_id', e.target.value === '' ? '' : Number(e.target.value))
                            }
                        >
                            <option value="">Nothing — this is a top-level category</option>
                            {parentOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {'— '.repeat(option.depth)}
                                    {option.name}
                                </option>
                            ))}
                        </Select>
                        <InputError message={form.errors.parent_id} className="mt-1" />
                    </div>

                    <div>
                        <label htmlFor="name" className="mb-1.5 block text-xs font-bold text-gray-700">
                            Name
                        </label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="e.g. Phones & Tablets"
                            autoFocus
                        />
                        <InputError message={form.errors.name} className="mt-1" />
                    </div>

                    <div>
                        <label htmlFor="description" className="mb-1.5 block text-xs font-bold text-gray-700">
                            Description <span className="font-normal text-gray-400">optional</span>
                        </label>
                        <Input
                            id="description"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            placeholder="Shown on the category page"
                        />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>

                    {/* No sort-order field. Categories list alphabetically
                        within each level, so there is nothing to number. */}
                    <div>
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Visibility</span>
                        <label className="flex cursor-pointer items-center gap-2.5 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                                className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                            />
                            Visible to shoppers and vendors
                        </label>
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
                            disabled={form.processing || form.data.name.trim() === ''}
                            className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:bg-gray-200 disabled:text-gray-400"
                        >
                            {editing ? 'Save changes' : 'Add category'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* ── Delete ── */}
            <Modal
                open={confirmDelete !== null}
                onClose={() => setConfirmDelete(null)}
                title={`Delete “${confirmDelete?.name}”?`}
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
                                form.delete(route('admin.catalog.categories.destroy', confirmDelete.id), {
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
                    {confirmDelete && confirmDelete.productCount > 0 ? (
                        <>
                            This category still holds{' '}
                            <strong>{confirmDelete.productCount} product(s)</strong>, so it cannot be
                            deleted. Move them elsewhere first, or set it to hidden instead.
                        </>
                    ) : confirmDelete && confirmDelete.childCount > 0 ? (
                        <>
                            This category still has <strong>{confirmDelete.childCount} sub-categories</strong>.
                            Delete or move those first.
                        </>
                    ) : (
                        'This cannot be undone.'
                    )}
                </p>
            </Modal>
        </AdminLayout>
    );
}
