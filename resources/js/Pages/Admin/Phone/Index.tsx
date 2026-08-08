import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Pagination } from '@/Components/ui/Pagination';
import PageHeader from '@/Components/ui/PageHeader';
import Reveal from '@/Components/ui/Reveal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Paginated } from '@/Types';
import { Head, router, usePage } from '@inertiajs/react';
import { ShieldCheck, Smartphone } from 'lucide-react';
import { useState } from 'react';

interface UserRow {
    uuid: string;
    name: string;
    email: string;
    phone: string;
    joinedAt: string;
}

interface Props {
    users: Paginated<UserRow>;
    flash: { success?: string };
    [key: string]: unknown;
}

/**
 * Manual phone-number verification queue — stands in while SMS OTP delivery
 * isn't reliable yet (SmartSMSSolutions transactional route pending).
 */
export default function AdminPhoneIndex() {
    const { users, flash } = usePage<Props>().props;
    const [rejectingUuid, setRejectingUuid] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const [busyUuid, setBusyUuid] = useState<string | null>(null);

    const approve = (uuid: string) => {
        setBusyUuid(uuid);
        router.post(route('admin.phone.approve', uuid), {}, { preserveScroll: true, onFinish: () => setBusyUuid(null) });
    };

    const submitReject = (uuid: string) => {
        if (!reason.trim()) return;
        setBusyUuid(uuid);
        router.post(
            route('admin.phone.reject', uuid),
            { reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectingUuid(null);
                    setReason('');
                },
                onFinish: () => setBusyUuid(null),
            },
        );
    };

    return (
        <AdminLayout>
            <Head title="Phone number review" />

            <PageHeader
                eyebrow="Compliance"
                title="Phone numbers"
                description="Manually verify customer phone numbers while SMS OTP delivery isn't reliable yet."
            />

            {flash.success && (
                <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700" role="status">
                    {flash.success}
                </p>
            )}

            <Reveal>
                <Card className="overflow-hidden p-0">
                    {users.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                                <ShieldCheck className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">Nothing pending</p>
                            <p className="mt-1 text-sm text-gray-500">Unverified phone numbers will appear here.</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-gray-100">
                            {users.data.map((user) => (
                                <div key={user.uuid} className="px-5 py-4">
                                    <div className="flex flex-wrap items-center gap-4">
                                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                                            <Smartphone className="h-5 w-5" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-semibold text-gray-900">{user.name}</p>
                                            <p className="truncate text-sm text-gray-500">
                                                {user.email} · {user.phone}
                                            </p>
                                            <p className="text-xs text-gray-400">Joined {user.joinedAt}</p>
                                        </div>
                                        <div className="flex shrink-0 gap-2">
                                            <Button
                                                type="button"
                                                disabled={busyUuid === user.uuid}
                                                onClick={() => approve(user.uuid)}
                                                className="text-sm"
                                            >
                                                Approve
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                disabled={busyUuid === user.uuid}
                                                onClick={() => setRejectingUuid(rejectingUuid === user.uuid ? null : user.uuid)}
                                                className="text-sm border-red-300 text-red-600 hover:bg-red-50"
                                            >
                                                Reject
                                            </Button>
                                        </div>
                                    </div>

                                    {rejectingUuid === user.uuid && (
                                        <div className="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-gray-50 p-3">
                                            <input
                                                autoFocus
                                                value={reason}
                                                onChange={(e) => setReason(e.target.value)}
                                                placeholder="Reason for rejection…"
                                                className="min-w-[200px] flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                                            />
                                            <Button
                                                type="button"
                                                disabled={!reason.trim() || busyUuid === user.uuid}
                                                onClick={() => submitReject(user.uuid)}
                                                className="text-sm border-red-600 bg-red-600 text-white hover:bg-red-700"
                                            >
                                                Confirm reject
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </Reveal>

            <Pagination links={users.links} />
        </AdminLayout>
    );
}
