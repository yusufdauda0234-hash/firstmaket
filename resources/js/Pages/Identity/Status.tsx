import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Fingerprint, Mail, ShieldCheck, Smartphone } from 'lucide-react';
import { ComponentType, FormEventHandler } from 'react';

interface Verification {
    type: string;
    status: string;
    failureReason: string | null;
    submittedAt: string;
}

interface Props {
    emailVerified: boolean;
    phoneVerified: boolean;
    identityStatus: string;
    verifications: Verification[];
    [key: string]: unknown;
}

/** A verification-status tile (email / phone / identity). */
function StatusTile({
    label,
    icon: Icon,
    verified,
    badge,
}: {
    label: string;
    icon: ComponentType<{ className?: string }>;
    verified: boolean;
    badge: React.ReactNode;
}) {
    return (
        <div className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-4 shadow-sm">
            <span
                className={cn(
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-full',
                    verified ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600',
                )}
            >
                <Icon className="h-5 w-5" />
            </span>
            <div className="min-w-0">
                <p className="text-xs font-medium text-gray-500">{label}</p>
                <div className="mt-0.5">{badge}</div>
            </div>
        </div>
    );
}

export default function IdentityStatus() {
    const { emailVerified, phoneVerified, identityStatus, verifications } = usePage<Props>().props;

    const bvnForm = useForm({ bvn: '' });
    const ninForm = useForm({ nin: '' });

    const submitBvn: FormEventHandler = (e) => {
        e.preventDefault();
        bvnForm.post(route('identity.bvn'), { onSuccess: () => bvnForm.reset() });
    };

    const submitNin: FormEventHandler = (e) => {
        e.preventDefault();
        ninForm.post(route('identity.nin'), { onSuccess: () => ninForm.reset() });
    };

    const isVerified = identityStatus === 'verified';

    return (
        <AccountLayout title="Identity verification">
            <Head title="Identity verification" />

            <h1 className="text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">
                Identity verification
            </h1>
            <p className="mt-1 text-sm text-gray-500">
                Confirm who you are to unlock Product Target Plans. Your BVN/NIN is encrypted and never shared.
            </p>

            {/* Status tiles */}
            <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <StatusTile
                    label="Email"
                    icon={Mail}
                    verified={emailVerified}
                    badge={
                        <Badge tone={emailVerified ? 'success' : 'warning'}>
                            {emailVerified ? 'Verified' : 'Unverified'}
                        </Badge>
                    }
                />
                <StatusTile
                    label="Phone"
                    icon={Smartphone}
                    verified={phoneVerified}
                    badge={
                        <Badge tone={phoneVerified ? 'success' : 'warning'}>
                            {phoneVerified ? 'Verified' : 'Unverified'}
                        </Badge>
                    }
                />
                <StatusTile
                    label="Identity (BVN/NIN)"
                    icon={Fingerprint}
                    verified={isVerified}
                    badge={<Badge tone={statusTone(identityStatus)}>{identityStatus.replace('_', ' ')}</Badge>}
                />
            </div>

            {isVerified ? (
                <div className="mt-4 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4">
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600" />
                    <p className="text-sm font-medium text-emerald-800">
                        Your identity is verified — Product Target Plans are unlocked.
                    </p>
                </div>
            ) : (
                <>
                    <div className="mt-4 flex items-start gap-3 rounded-2xl border border-brand-100 bg-brand-50/60 px-5 py-4">
                        <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
                        <p className="text-sm text-gray-600">
                            Verify your BVN or NIN to unlock Product Target Plans. Open Savings is available
                            without this step.
                        </p>
                    </div>

                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Card>
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-bold text-gray-900">
                                <Fingerprint className="h-4 w-4 text-brand-600" /> Verify with BVN
                            </h2>
                            <form onSubmit={submitBvn} className="space-y-4">
                                <div>
                                    <Label htmlFor="bvn">BVN (11 digits)</Label>
                                    <Input
                                        id="bvn"
                                        inputMode="numeric"
                                        maxLength={11}
                                        value={bvnForm.data.bvn}
                                        onChange={(e) => bvnForm.setData('bvn', e.target.value)}
                                        required
                                    />
                                    <InputError message={bvnForm.errors.bvn} />
                                </div>
                                <Button type="submit" disabled={bvnForm.processing} className="w-full">
                                    {bvnForm.processing ? 'Verifying…' : 'Verify BVN'}
                                </Button>
                            </form>
                        </Card>

                        <Card>
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-bold text-gray-900">
                                <Fingerprint className="h-4 w-4 text-brand-600" /> Verify with NIN
                            </h2>
                            <form onSubmit={submitNin} className="space-y-4">
                                <div>
                                    <Label htmlFor="nin">NIN (11 digits)</Label>
                                    <Input
                                        id="nin"
                                        inputMode="numeric"
                                        maxLength={11}
                                        value={ninForm.data.nin}
                                        onChange={(e) => ninForm.setData('nin', e.target.value)}
                                        required
                                    />
                                    <InputError message={ninForm.errors.nin} />
                                </div>
                                <Button type="submit" disabled={ninForm.processing} className="w-full">
                                    {ninForm.processing ? 'Verifying…' : 'Verify NIN'}
                                </Button>
                            </form>
                        </Card>
                    </div>
                </>
            )}

            {verifications.length > 0 && (
                <Card className="mt-6 p-0">
                    <h2 className="border-b border-gray-100 px-5 py-4 text-sm font-bold text-gray-900">History</h2>
                    <ul className="divide-y divide-gray-100">
                        {verifications.map((verification, index) => (
                            <li key={index} className="flex items-center justify-between gap-3 px-5 py-3.5 text-sm">
                                <div className="min-w-0">
                                    <span className="font-semibold uppercase text-gray-900">
                                        {verification.type}
                                    </span>
                                    <span className="ml-2 text-gray-400">{verification.submittedAt}</span>
                                    {verification.failureReason && (
                                        <p className="mt-1 text-red-600">{verification.failureReason}</p>
                                    )}
                                </div>
                                <Badge tone={statusTone(verification.status)}>{verification.status}</Badge>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </AccountLayout>
    );
}
