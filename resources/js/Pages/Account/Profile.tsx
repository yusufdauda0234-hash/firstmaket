import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import AccountLayout from '@/Layouts/AccountLayout';
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
    };
}

export default function AccountProfile() {
    const { account } = usePage<Props>().props;
    const form = useForm({ name: account.name });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('account.profile.update'), { preserveScroll: true });
    };

    return (
        <AccountLayout title="Profile">
            <Head title="Profile" />
            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">Profile</h1>
            <p className="mt-1 text-sm text-gray-500">Keep your account details current.</p>

            <div className="mt-6 grid max-w-4xl gap-6 lg:grid-cols-2">
                <Card>
                    <h2 className="text-lg font-medium text-gray-900">Personal details</h2>
                    <form onSubmit={submit} className="mt-4 space-y-4">
                        <div>
                            <Label htmlFor="name">Full name</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            <InputError message={form.errors.name} />
                        </div>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save changes'}</Button>
                    </form>
                </Card>
                <Card>
                    <h2 className="text-lg font-medium text-gray-900">Sign-in details</h2>
                    <div className="mt-4 space-y-3">
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3">
                            <span className="truncate text-sm text-gray-700">{account.email ?? 'No email added'}</span>
                            {account.email && <Badge tone={account.emailVerified ? 'success' : 'warning'}>{account.emailVerified ? 'verified' : 'unverified'}</Badge>}
                        </div>
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3">
                            <span className="truncate text-sm text-gray-700">{account.phone ?? 'No phone added'}</span>
                            {account.phone && <Badge tone={account.phoneVerified ? 'success' : 'warning'}>{account.phoneVerified ? 'verified' : 'unverified'}</Badge>}
                        </div>
                        <a href={route('account.settings')} className="inline-block text-sm font-semibold text-brand-600 hover:underline">Manage sign-in methods</a>
                    </div>
                </Card>
            </div>
        </AccountLayout>
    );
}