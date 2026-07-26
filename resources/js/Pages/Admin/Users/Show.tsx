import { Badge, statusTone } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ClipboardList, Mail, Phone, PiggyBank, RotateCcw, ShieldOff, Wallet } from 'lucide-react';
import { useState } from 'react';

interface Props {
    user: {
        uuid: string;
        name: string;
        email: string | null;
        phone: string | null;
        status: string;
        statusReason: string | null;
        statusChangedBy: string | null;
        statusChangedAt: string | null;
        joinedAt: string;
        walletBalanceKobo: number;
        orderCount: number;
        planCount: number;
    };
    [key: string]: unknown;
}

type Action = 'suspend' | 'ban' | 'reactivate';

const actionConfig: Record<
    Action,
    { title: string; blurb: string; confirm: string; icon: typeof ShieldOff; danger: boolean; needsReason: boolean }
> = {
    suspend: {
        title: 'Suspend this account?',
        blurb: 'Their session ends on their next request; they cannot log in again until reactivated.',
        confirm: 'Suspend account',
        icon: ShieldOff,
        danger: true,
        needsReason: true,
    },
    ban: {
        title: 'Ban this account?',
        blurb: 'A ban is a stronger, more permanent measure than a suspension — use it for serious violations.',
        confirm: 'Ban account',
        icon: AlertTriangle,
        danger: true,
        needsReason: true,
    },
    reactivate: {
        title: 'Reactivate this account?',
        blurb: 'The customer can log in again immediately.',
        confirm: 'Reactivate account',
        icon: RotateCcw,
        danger: false,
        needsReason: false,
    },
};

export default function UserShow() {
    const { user } = usePage<Props>().props;
    const [action, setAction] = useState<Action | null>(null);
    const suspendForm = useForm({ reason: '' });
    const banForm = useForm({ reason: '' });
    const reactivateForm = useForm({});

    const busy = suspendForm.processing || banForm.processing || reactivateForm.processing;
    const config = action ? actionConfig[action] : null;
    const reasonForm = action === 'ban' ? banForm : suspendForm;

    const confirmAction = () => {
        if (!action) return;
        const opts = { preserveScroll: true, onSuccess: () => setAction(null) };
        if (action === 'suspend') suspendForm.post(route('admin.users.suspend', user.uuid), opts);
        if (action === 'ban') banForm.post(route('admin.users.ban', user.uuid), opts);
        if (action === 'reactivate') reactivateForm.post(route('admin.users.reactivate', user.uuid), opts);
    };

    const isModerated = user.status === 'suspended' || user.status === 'banned';

    return (
        <AdminLayout>
            <Head title={user.name} />

            <PageHeader eyebrow="Customer account" title={user.name} backHref={route('admin.users.index')} />

            <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
                <div className="space-y-4">
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-bold text-gray-900">Account details</h2>
                            <Badge tone={statusTone(user.status)}>{user.status.replace('_', ' ')}</Badge>
                        </div>
                        <dl className="mt-3 space-y-2 text-sm">
                            {user.email && (
                                <div className="flex items-center gap-2 text-gray-600">
                                    <Mail className="h-4 w-4 text-gray-400" /> {user.email}
                                </div>
                            )}
                            {user.phone && (
                                <div className="flex items-center gap-2 text-gray-600">
                                    <Phone className="h-4 w-4 text-gray-400" /> {user.phone}
                                </div>
                            )}
                            <div className="text-gray-500">Joined {user.joinedAt}</div>
                        </dl>
                        {isModerated && (
                            <div className="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">
                                <p className="font-semibold">Reason: {user.statusReason ?? '—'}</p>
                                {user.statusChangedBy && (
                                    <p className="mt-0.5 text-xs text-red-600">
                                        By {user.statusChangedBy}
                                        {user.statusChangedAt && ` on ${user.statusChangedAt}`}
                                    </p>
                                )}
                            </div>
                        )}
                    </Card>

                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <Card className="flex items-center gap-3">
                            <Wallet className="h-5 w-5 text-brand-600" />
                            <div>
                                <p className="text-xs text-gray-400">Wallet</p>
                                <p className="text-sm font-bold text-gray-900">
                                    {formatNairaFromKobo(user.walletBalanceKobo)}
                                </p>
                            </div>
                        </Card>
                        <Card className="flex items-center gap-3">
                            <ClipboardList className="h-5 w-5 text-brand-600" />
                            <div>
                                <p className="text-xs text-gray-400">Orders</p>
                                <p className="text-sm font-bold text-gray-900">{user.orderCount}</p>
                            </div>
                        </Card>
                        <Card className="flex items-center gap-3">
                            <PiggyBank className="h-5 w-5 text-brand-600" />
                            <div>
                                <p className="text-xs text-gray-400">Plans</p>
                                <p className="text-sm font-bold text-gray-900">{user.planCount}</p>
                            </div>
                        </Card>
                    </div>
                </div>

                <div className="space-y-3">
                    <Card>
                        <h3 className="text-sm font-bold text-gray-900">Account controls</h3>
                        <p className="mt-1 text-xs text-gray-500">
                            Suspending or banning ends the account's session on its next request automatically.
                        </p>
                        <div className="mt-3 space-y-2">
                            {isModerated ? (
                                <Button onClick={() => setAction('reactivate')} className="w-full active:scale-95">
                                    Reactivate account
                                </Button>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setAction('suspend')}
                                        className="w-full rounded-full border border-amber-300 bg-amber-50 py-2 text-sm font-bold text-amber-700 transition hover:bg-amber-100 active:scale-95"
                                    >
                                        Suspend account
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setAction('ban')}
                                        className="w-full rounded-full border border-red-300 bg-red-50 py-2 text-sm font-bold text-red-700 transition hover:bg-red-100 active:scale-95"
                                    >
                                        Ban account
                                    </button>
                                </>
                            )}
                        </div>
                    </Card>
                </div>
            </div>

            <Modal
                open={action !== null}
                onClose={() => setAction(null)}
                icon={config ? <config.icon className="h-6 w-6" /> : undefined}
                iconAccent={config?.danger ? 'bg-red-50 text-red-600' : 'bg-brand-50 text-brand-600'}
                title={config?.title}
                description={config?.blurb}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setAction(null)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmAction}
                            disabled={busy}
                            className={
                                config?.danger
                                    ? 'bg-red-600 hover:bg-red-700 focus-visible:outline-red-600 active:scale-95'
                                    : 'active:scale-95'
                            }
                        >
                            {busy ? 'Working…' : config?.confirm}
                        </Button>
                    </>
                }
            >
                {config?.needsReason && (
                    <div>
                        <Label htmlFor="action-reason">Reason</Label>
                        <textarea
                            id="action-reason"
                            rows={4}
                            autoFocus
                            value={reasonForm.data.reason}
                            onChange={(e) => reasonForm.setData('reason', e.target.value)}
                            className="mt-1 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            required
                        />
                        <InputError message={reasonForm.errors.reason} />
                    </div>
                )}
            </Modal>
        </AdminLayout>
    );
}
