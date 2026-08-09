import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import VendorLayout from '@/Layouts/VendorLayout';
import { PageProps } from '@/Types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props extends PageProps {
    account: {
        name: string;
        email: string | null;
        phone: string | null;
        emailVerified: boolean;
        phoneVerified: boolean;
        businessName: string | null;
        contactName: string | null;
        address: string | null;
        status: string | null;
    };
}

export default function VendorProfile() {
    const { account } = usePage<Props>().props;
    const profileForm = useForm({ name: account.name, business_name: account.businessName ?? '', contact_name: account.contactName ?? '', address: account.address ?? '' });
    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

    const saveProfile: FormEventHandler = (event) => {
        event.preventDefault();
        profileForm.put(route('vendor.profile.update'), { preserveScroll: true });
    };
    const savePassword: FormEventHandler = (event) => {
        event.preventDefault();
        passwordForm.put(route('vendor.profile.password'), { preserveScroll: true, onSuccess: () => passwordForm.reset() });
    };

    return (
        <VendorLayout>
            <Head title="Profile" />
            <h1 className="text-2xl font-extrabold tracking-tight text-gray-900">Profile</h1>
            <p className="mt-1 text-sm text-gray-500">Manage your seller and contact details.</p>
            <div className="mt-6 grid gap-6 lg:grid-cols-2">
                <Card>
                    <h2 className="text-lg font-medium text-gray-900">Seller details</h2>
                    <form onSubmit={saveProfile} className="mt-4 space-y-4">
                        {(['name', 'business_name', 'contact_name', 'address'] as const).map((field) => (
                            <div key={field}>
                                <Label htmlFor={field}>{field === 'name' ? 'Your name' : field === 'business_name' ? 'Business name' : field === 'contact_name' ? 'Business contact name' : 'Business address'}</Label>
                                <Input id={field} value={profileForm.data[field]} onChange={(e) => profileForm.setData(field, e.target.value)} required={field !== 'address'} />
                                <InputError message={profileForm.errors[field]} />
                            </div>
                        ))}
                        <Button type="submit" disabled={profileForm.processing}>{profileForm.processing ? 'Saving…' : 'Save changes'}</Button>
                    </form>
                </Card>
                <Card>
                    <h2 className="text-lg font-medium text-gray-900">Account access</h2>
                    <div className="mt-4 space-y-3 text-sm text-gray-700">
                        <p>{account.email ?? 'No email added'} {account.email && <Badge tone={account.emailVerified ? 'success' : 'warning'}>{account.emailVerified ? 'verified' : 'unverified'}</Badge>}</p>
                        <p>{account.phone ?? 'No phone added'} {account.phone && <Badge tone={account.phoneVerified ? 'success' : 'warning'}>{account.phoneVerified ? 'verified' : 'unverified'}</Badge>}</p>
                        <p className="capitalize">Application status: {account.status ?? 'pending'}</p>
                    </div>
                    <form onSubmit={savePassword} className="mt-6 space-y-3 border-t border-gray-100 pt-5">
                        <h3 className="font-medium text-gray-900">Change password</h3>
                        {(['current_password', 'password', 'password_confirmation'] as const).map((field) => (
                            <div key={field}><Label htmlFor={`vendor-${field}`}>{field === 'current_password' ? 'Current password' : field === 'password' ? 'New password' : 'Confirm new password'}</Label><Input id={`vendor-${field}`} type="password" autoComplete={field === 'current_password' ? 'current-password' : 'new-password'} value={passwordForm.data[field]} onChange={(e) => passwordForm.setData(field, e.target.value)} required /><InputError message={passwordForm.errors[field]} /></div>
                        ))}
                        <Button type="submit" disabled={passwordForm.processing}>{passwordForm.processing ? 'Saving…' : 'Update password'}</Button>
                    </form>
                </Card>
            </div>
        </VendorLayout>
    );
}