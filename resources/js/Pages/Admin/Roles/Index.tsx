import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import { Textarea } from '@/Components/ui/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface RoleItem {
    id: number;
    name: string;
    description: string | null;
    isSystem: boolean;
    permissions: string[];
    staffCount: number;
}

interface Props {
    roles: RoleItem[];
    permissionGroups: Record<string, Record<string, string>>;
    [key: string]: unknown;
}

export default function RolesIndex() {
    const { roles = [], permissionGroups = {} } = usePage<Props>().props;
    const [editing, setEditing] = useState<RoleItem | null | undefined>(undefined);

    return (
        <AdminLayout>
            <Head title="Roles" />
            <PageHeader
                title="Staff roles"
                description="Compose the access a job needs, then assign it from the staff screen."
                actions={<Button onClick={() => setEditing(null)}>Create role</Button>}
            />

            <div className="grid gap-4 lg:grid-cols-2">
                {roles.map((role) => (
                    <Card key={role.id} className="flex flex-col gap-4">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="font-bold text-gray-900">{role.name}</h2>
                                <p className="mt-1 text-sm text-gray-500">{role.description || 'No description yet.'}</p>
                            </div>
                            {role.isSystem && (
                                <span className="rounded-full bg-gray-100 px-2 py-1 text-[11px] font-bold text-gray-600">System</span>
                            )}
                        </div>
                        <p className="text-xs text-gray-500">
                            {role.permissions.length} permissions
                            {role.staffCount > 0 && ` · ${role.staffCount} staff assigned`}
                        </p>
                        <div className="flex gap-2 border-t border-gray-100 pt-3">
                            <Button variant="secondary" onClick={() => setEditing(role)}>Edit permissions</Button>
                            {!role.isSystem && (
                                <Button
                                    variant="ghost"
                                    disabled={role.staffCount > 0}
                                    title={role.staffCount > 0 ? 'Reassign staff off this role before deleting it.' : undefined}
                                    onClick={() => {
                                        if (!confirm(`Delete the "${role.name}" role? This cannot be undone.`)) return;
                                        router.delete(route('admin.roles.destroy', role.id));
                                    }}
                                >
                                    Delete
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>

            {editing !== undefined && (
                <RoleForm
                    role={editing}
                    permissionGroups={permissionGroups}
                    onClose={() => setEditing(undefined)}
                />
            )}
        </AdminLayout>
    );
}

function RoleForm({
    role,
    permissionGroups,
    onClose,
}: {
    role: RoleItem | null;
    permissionGroups: Record<string, Record<string, string>>;
    onClose: () => void;
}) {
    const form = useForm({
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: role?.permissions ?? [],
    });

    const togglePermission = (permission: string) => {
        form.setData(
            'permissions',
            form.data.permissions.includes(permission)
                ? form.data.permissions.filter((item) => item !== permission)
                : [...form.data.permissions, permission],
        );
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        role
            ? form.put(route('admin.roles.update', role.id), options)
            : form.post(route('admin.roles.store'), options);
    };

    return (
        <Modal open onClose={onClose} title={role ? `Edit ${role.name}` : 'Create a staff role'} size="xl">
            <form onSubmit={submit} className="space-y-5">
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Role name</span>
                        <Input value={form.data.name} disabled={role?.isSystem} onChange={(event) => form.setData('name', event.target.value)} />
                        <InputError message={form.errors.name} className="mt-1" />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold text-gray-600">Description</span>
                        <Textarea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                        <InputError message={form.errors.description} className="mt-1" />
                    </label>
                </div>

                <div>
                    <p className="mb-3 text-xs font-bold uppercase tracking-wide text-gray-500">Permissions</p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {Object.entries(permissionGroups).map(([group, permissions]) => (
                            <fieldset key={group} className="rounded-xl border border-gray-100 p-3">
                                <legend className="px-1 text-sm font-bold text-gray-800">{group}</legend>
                                <div className="mt-2 space-y-2">
                                    {Object.entries(permissions).map(([permission, label]) => (
                                        <label key={permission} className="flex items-start gap-2 text-sm text-gray-600">
                                            <input
                                                type="checkbox"
                                                checked={form.data.permissions.includes(permission)}
                                                onChange={() => togglePermission(permission)}
                                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                            />
                                            <span>{label}</span>
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        ))}
                    </div>
                    <InputError message={form.errors.permissions} className="mt-2" />
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                    <Button type="submit" disabled={form.processing}>Save role</Button>
                </div>
            </form>
        </Modal>
    );
}