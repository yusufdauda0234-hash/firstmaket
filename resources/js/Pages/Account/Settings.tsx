import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import AccountLayout from '@/Layouts/AccountLayout';
import { PageProps } from '@/Types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Props extends PageProps {
    account: {
        name: string;
        email: string | null;
        emailVerified: boolean;
        phone: string | null;
        phoneVerified: boolean;
        hasPassword: boolean;
        socialAccounts: { provider: string; providerEmail: string | null; linkedAt: string }[];
    };
}

/** Row showing one sign-in identifier with its verification badge. */
function IdentifierRow({ label, value, verified }: { label: string; value: string; verified: boolean }) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3">
            <div className="min-w-0">
                <p className="text-xs text-gray-500">{label}</p>
                <p className="truncate text-sm font-medium text-gray-900">{value}</p>
            </div>
            <Badge tone={verified ? 'success' : 'warning'}>{verified ? 'verified' : 'unverified'}</Badge>
        </div>
    );
}

export default function AccountSettings() {
    const { account, flash } = usePage<Props>().props;

    // Two-step add-identifier flow: enter identifier → enter OTP code.
    const [codeSent, setCodeSent] = useState(false);
    const identifierForm = useForm({ identifier: '', code: '' });

    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });
    const unlinkForm = useForm({});

    const missingLabel = account.email === null ? 'email address' : 'phone number';
    const canAddIdentifier = account.email === null || account.phone === null;

    const sendCode: FormEventHandler = (e) => {
        e.preventDefault();
        identifierForm.post(route('account.identifier.send'), {
            preserveScroll: true,
            onSuccess: () => setCodeSent(true),
        });
    };

    const confirmCode: FormEventHandler = (e) => {
        e.preventDefault();
        identifierForm.post(route('account.identifier.confirm'), {
            preserveScroll: true,
            onSuccess: () => {
                setCodeSent(false);
                identifierForm.reset();
            },
        });
    };

    const savePassword: FormEventHandler = (e) => {
        e.preventDefault();
        passwordForm.put(route('account.password'), {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    return (
        <AccountLayout title="Account settings">
            <Head title="Account settings" />

            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">Account settings</h1>
            <p className="mt-1 text-sm text-gray-500">
                Manage how you sign in: email, phone number, password, and linked social accounts.
            </p>

            {flash.success && (
                <p className="mt-4 rounded-md bg-green-50 px-4 py-2.5 text-sm text-green-700" role="status">
                    {flash.success}
                </p>
            )}

            <div className="mt-6 grid max-w-4xl gap-6 lg:grid-cols-2">
                {/* Sign-in identifiers */}
                <Card>
                    <h2 className="mb-1 text-lg font-medium text-gray-900">Sign-in identifiers</h2>
                    <p className="mb-4 text-sm text-gray-500">
                        A verified phone number is required before wallet funding.
                    </p>

                    <div className="space-y-3">
                        {account.email !== null && (
                            <IdentifierRow label="Email" value={account.email} verified={account.emailVerified} />
                        )}
                        {account.phone !== null && (
                            <IdentifierRow label="Phone" value={account.phone} verified={account.phoneVerified} />
                        )}
                    </div>

                    {canAddIdentifier && (
                        <div className="mt-4 border-t border-gray-100 pt-4">
                            {!codeSent ? (
                                <form onSubmit={sendCode} className="space-y-3">
                                    <div>
                                        <Label htmlFor="identifier">Add your {missingLabel}</Label>
                                        <Input
                                            id="identifier"
                                            placeholder={account.email === null ? 'you@example.com' : '+2348012345678'}
                                            value={identifierForm.data.identifier}
                                            onChange={(e) => identifierForm.setData('identifier', e.target.value)}
                                            required
                                        />
                                        <InputError message={identifierForm.errors.identifier} />
                                    </div>
                                    <Button type="submit" variant="secondary" disabled={identifierForm.processing}>
                                        {identifierForm.processing ? 'Sending…' : 'Send verification code'}
                                    </Button>
                                </form>
                            ) : (
                                <form onSubmit={confirmCode} className="space-y-3">
                                    <div>
                                        <Label htmlFor="code">Enter the 6-digit code</Label>
                                        <Input
                                            id="code"
                                            inputMode="numeric"
                                            maxLength={6}
                                            autoComplete="one-time-code"
                                            value={identifierForm.data.code}
                                            onChange={(e) => identifierForm.setData('code', e.target.value)}
                                            required
                                        />
                                        <InputError message={identifierForm.errors.code} />
                                        <InputError message={identifierForm.errors.identifier} />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" disabled={identifierForm.processing}>
                                            {identifierForm.processing ? 'Verifying…' : 'Verify and add'}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => {
                                                setCodeSent(false);
                                                identifierForm.setData('code', '');
                                            }}
                                        >
                                            Change {missingLabel}
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </div>
                    )}
                </Card>

                {/* Password */}
                <Card>
                    <h2 className="mb-1 text-lg font-medium text-gray-900">
                        {account.hasPassword ? 'Change password' : 'Set a password'}
                    </h2>
                    <p className="mb-4 text-sm text-gray-500">
                        {account.hasPassword
                            ? 'Choose a strong password you don’t use elsewhere.'
                            : 'Your account currently signs in without a password (social or OTP only). Set one to add a second way in.'}
                    </p>

                    <form onSubmit={savePassword} className="space-y-3">
                        {account.hasPassword && (
                            <div>
                                <Label htmlFor="current_password">Current password</Label>
                                <Input
                                    id="current_password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={passwordForm.data.current_password}
                                    onChange={(e) => passwordForm.setData('current_password', e.target.value)}
                                    required
                                />
                                <InputError message={passwordForm.errors.current_password} />
                            </div>
                        )}
                        <div>
                            <Label htmlFor="password">New password</Label>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={passwordForm.data.password}
                                onChange={(e) => passwordForm.setData('password', e.target.value)}
                                required
                            />
                            <InputError message={passwordForm.errors.password} />
                        </div>
                        <div>
                            <Label htmlFor="password_confirmation">Confirm new password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={passwordForm.data.password_confirmation}
                                onChange={(e) => passwordForm.setData('password_confirmation', e.target.value)}
                                required
                            />
                            <InputError message={passwordForm.errors.password_confirmation} />
                        </div>
                        <Button type="submit" disabled={passwordForm.processing}>
                            {passwordForm.processing
                                ? 'Saving…'
                                : account.hasPassword
                                  ? 'Update password'
                                  : 'Set password'}
                        </Button>
                        {passwordForm.recentlySuccessful && (
                            <span className="ml-3 text-sm font-medium text-green-600">Saved.</span>
                        )}
                    </form>
                </Card>

                {/* Linked social accounts */}
                <Card className="lg:col-span-2">
                    <h2 className="mb-1 text-lg font-medium text-gray-900">Linked accounts</h2>
                    <p className="mb-4 text-sm text-gray-500">
                        Social logins linked to this account. You can’t unlink your only way of signing in —
                        set a password first.
                    </p>

                    {account.socialAccounts.length === 0 ? (
                        <p className="text-sm text-gray-500">
                            No social accounts linked. Use “Continue with Google/Facebook” on the sign-in
                            page while logged out to link one by email.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {account.socialAccounts.map((social) => (
                                <li
                                    key={social.provider}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3"
                                >
                                    <div>
                                        <p className="text-sm font-semibold capitalize text-gray-900">
                                            {social.provider}
                                        </p>
                                        <p className="text-xs text-gray-500">
                                            {social.providerEmail ?? 'No email shared'} · linked {social.linkedAt}
                                        </p>
                                    </div>
                                    <Button
                                        variant="secondary"
                                        disabled={unlinkForm.processing}
                                        onClick={() =>
                                            unlinkForm.delete(route('account.social.unlink', social.provider), {
                                                preserveScroll: true,
                                            })
                                        }
                                        className="border-red-300 text-red-600 hover:bg-red-50"
                                    >
                                        Unlink
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <InputError message={(unlinkForm.errors as Record<string, string>).provider} />
                </Card>
            </div>
        </AccountLayout>
    );
}
