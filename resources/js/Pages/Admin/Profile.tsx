import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/Types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props extends PageProps { account: { name: string; email: string | null; phone: string | null; emailVerified: boolean; phoneVerified: boolean; roles: string[] } }

export default function AdminProfile() {
    const { account } = usePage<Props>().props;
    const profileForm = useForm({ name: account.name });
    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });
    const saveProfile: FormEventHandler = (event) => { event.preventDefault(); profileForm.put(route('admin.profile.update'), { preserveScroll: true }); };
    const savePassword: FormEventHandler = (event) => { event.preventDefault(); passwordForm.put(route('admin.profile.password'), { preserveScroll: true, onSuccess: () => passwordForm.reset() }); };

    return (
        <AdminLayout>
            <Head title="Profile" />
            <h1 className="text-2xl font-extrabold tracking-tight text-gray-900">Profile</h1>
            <p className="mt-1 text-sm text-gray-500">Manage your personal details and account access.</p>
            <div className="mt-6 grid max-w-4xl gap-6 lg:grid-cols-2">
                <Card><h2 className="text-lg font-medium text-gray-900">Personal details</h2><form onSubmit={saveProfile} className="mt-4 space-y-4"><div><Label htmlFor="admin-name">Full name</Label><Input id="admin-name" value={profileForm.data.name} onChange={(e) => profileForm.setData('name', e.target.value)} required /><InputError message={profileForm.errors.name} /></div><Button type="submit" disabled={profileForm.processing}>{profileForm.processing ? 'Saving…' : 'Save changes'}</Button></form><div className="mt-6 space-y-2 border-t border-gray-100 pt-5 text-sm text-gray-700"><p>{account.email ?? 'No email added'} {account.email && <Badge tone={account.emailVerified ? 'success' : 'warning'}>{account.emailVerified ? 'verified' : 'unverified'}</Badge>}</p><p>{account.phone ?? 'No phone added'} {account.phone && <Badge tone={account.phoneVerified ? 'success' : 'warning'}>{account.phoneVerified ? 'verified' : 'unverified'}</Badge>}</p><p className="text-xs text-gray-500">Roles: {account.roles.join(', ') || 'None assigned'}</p></div></Card>
                <Card><h2 className="text-lg font-medium text-gray-900">Change password</h2><form onSubmit={savePassword} className="mt-4 space-y-3">{(['current_password', 'password', 'password_confirmation'] as const).map((field) => <div key={field}><Label htmlFor={`admin-${field}`}>{field === 'current_password' ? 'Current password' : field === 'password' ? 'New password' : 'Confirm new password'}</Label><Input id={`admin-${field}`} type="password" autoComplete={field === 'current_password' ? 'current-password' : 'new-password'} value={passwordForm.data[field]} onChange={(e) => passwordForm.setData(field, e.target.value)} required /><InputError message={passwordForm.errors[field]} /></div>)}<Button type="submit" disabled={passwordForm.processing}>{passwordForm.processing ? 'Saving…' : 'Update password'}</Button></form></Card>
            </div>
        </AdminLayout>
    );
}