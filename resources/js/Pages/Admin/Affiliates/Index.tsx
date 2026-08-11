import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, BadgeCheck, Check, Pause, Play, X } from 'lucide-react';

interface BankAccount {
    id: number;
    bankName: string;
    accountName: string;
    maskedNumber: string;
    verified: boolean;
}

interface Application {
    uuid: number;
    name: string;
    userName: string;
    email: string | null;
    status: string;
    appliedAt: string;
    tier: string | null;
    qualifiedCount: number;
    openFlagCount: number;
    suspensionReason: string | null;
    pendingKobo: number;
    bankAccount: BankAccount | null;
}

interface Flag {
    id: number;
    reason: string;
    detail: string | null;
    status: string;
}

interface ReviewItem {
    id: number;
    affiliate: string | null;
    type: string;
    valueKobo: number;
    qualifiedAt: string | null;
    flags: Flag[];
}

const FLAG_LABELS: Record<string, string> = {
    velocity: 'Converted suspiciously fast',
    self_dealing: 'Looks like the partner themselves',
    value_anomaly: 'Order value far outside their normal',
};

const prompt = (message: string) => {
    const reason = window.prompt(message);

    return reason && reason.trim() !== '' ? reason.trim() : null;
};

export default function Index({ applications = [], reviewQueue = [] }: { applications: Application[]; reviewQueue: ReviewItem[] }) {
    return (
        <AdminLayout>
            <Head title="Affiliates" />
            <PageHeader
                title="Affiliates"
                description="Approve partners, clear flagged conversions, and verify where their money is sent."
            />

            {reviewQueue.length > 0 && (
                <Card className="mb-6 !p-0 overflow-hidden">
                    <div className="flex items-center gap-2 border-b border-gray-100 px-5 py-3.5">
                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                        <h2 className="font-bold text-gray-900">Conversions waiting on a decision ({reviewQueue.length})</h2>
                    </div>
                    <p className="px-5 pt-3 text-xs text-gray-500">
                        A flagged conversion earns nothing until it is cleared. Clearing it records the commission;
                        rejecting it voids the commission permanently.
                    </p>
                    <div className="divide-y divide-gray-50">
                        {reviewQueue.map((item) => (
                            <div key={item.id} className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-bold text-gray-900">
                                        {item.affiliate} · <span className="font-medium text-gray-500">{item.type.replace(/_/g, ' ')}</span>
                                    </p>
                                    <p className="text-xs text-gray-500">
                                        {formatNairaFromKobo(item.valueKobo)} · {item.qualifiedAt}
                                    </p>
                                    <ul className="mt-2 space-y-1">
                                        {item.flags.map((flag) => (
                                            <li key={flag.id} className="text-xs text-amber-700">
                                                <span className="font-semibold">{FLAG_LABELS[flag.reason] ?? flag.reason}</span>
                                                {flag.detail && <span className="text-amber-600"> — {flag.detail}</span>}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                                <div className="flex shrink-0 gap-2">
                                    <button
                                        onClick={() => router.post(route('admin.affiliates.conversions.approve', item.id), {}, { preserveScroll: true })}
                                        className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white"
                                    >
                                        Clear & pay
                                    </button>
                                    <button
                                        onClick={() => {
                                            const reason = prompt('Why is this conversion being rejected?');
                                            if (reason) {
                                                router.post(route('admin.affiliates.conversions.reject', item.id), { reason }, { preserveScroll: true });
                                            }
                                        }}
                                        className="rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white"
                                    >
                                        Reject
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </Card>
            )}

            <Card className="overflow-hidden !p-0">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                {['Applicant', 'Tier', 'Delivered', 'Owed', 'Payout account', 'Status', 'Action'].map((heading) => (
                                    <th key={heading} className="whitespace-nowrap px-4 py-3 text-left font-semibold text-gray-600">
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {applications.map((application) => (
                                <tr key={application.uuid}>
                                    <td className="px-4 py-3">
                                        <p className="font-semibold">{application.userName}</p>
                                        <p className="text-xs text-gray-500">{application.email}</p>
                                        <p className="text-xs text-gray-400">{application.name}</p>
                                        {application.openFlagCount > 0 && (
                                            <p className="mt-1 inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-bold text-amber-700">
                                                <AlertTriangle className="h-3 w-3" /> {application.openFlagCount} open flag(s)
                                            </p>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">{application.tier ?? '—'}</td>
                                    <td className="px-4 py-3">{application.qualifiedCount}</td>
                                    <td className="whitespace-nowrap px-4 py-3">{formatNairaFromKobo(application.pendingKobo)}</td>
                                    <td className="px-4 py-3">
                                        {application.bankAccount ? (
                                            <div>
                                                <p className="text-xs font-semibold text-gray-700">{application.bankAccount.accountName}</p>
                                                <p className="text-xs text-gray-500">
                                                    {application.bankAccount.bankName} · {application.bankAccount.maskedNumber}
                                                </p>
                                                {application.bankAccount.verified ? (
                                                    <span className="mt-1 inline-flex items-center gap-1 text-[11px] font-bold text-emerald-600">
                                                        <BadgeCheck className="h-3 w-3" /> Verified
                                                    </span>
                                                ) : (
                                                    <button
                                                        onClick={() =>
                                                            router.post(
                                                                route('admin.affiliates.bank-accounts.verify', application.bankAccount!.id),
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="mt-1 rounded bg-brand-600 px-2 py-1 text-[11px] font-bold text-white"
                                                    >
                                                        Verify
                                                    </button>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-xs text-gray-400">Not provided</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="capitalize">{application.status}</span>
                                        {application.suspensionReason && (
                                            <p className="text-xs text-red-600">{application.suspensionReason}</p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="inline-flex gap-2">
                                            {application.status === 'pending' && (
                                                <>
                                                    <button
                                                        title="Approve"
                                                        onClick={() => router.post(route('admin.affiliates.approve', application.uuid), {}, { preserveScroll: true })}
                                                        className="rounded-lg bg-emerald-600 p-2 text-white"
                                                    >
                                                        <Check className="h-4 w-4" />
                                                    </button>
                                                    <button
                                                        title="Reject"
                                                        onClick={() => {
                                                            const reason = prompt('Why is this application being rejected?');
                                                            if (reason) {
                                                                router.post(route('admin.affiliates.reject', application.uuid), { reason }, { preserveScroll: true });
                                                            }
                                                        }}
                                                        className="rounded-lg bg-red-600 p-2 text-white"
                                                    >
                                                        <X className="h-4 w-4" />
                                                    </button>
                                                </>
                                            )}
                                            {application.status === 'approved' && (
                                                <button
                                                    title="Suspend"
                                                    onClick={() => {
                                                        const reason = prompt('Why is this partner being suspended?');
                                                        if (reason) {
                                                            router.post(route('admin.affiliates.suspend', application.uuid), { reason }, { preserveScroll: true });
                                                        }
                                                    }}
                                                    className="rounded-lg bg-amber-600 p-2 text-white"
                                                >
                                                    <Pause className="h-4 w-4" />
                                                </button>
                                            )}
                                            {application.status === 'suspended' && (
                                                <button
                                                    title="Reinstate"
                                                    onClick={() => router.post(route('admin.affiliates.reinstate', application.uuid), {}, { preserveScroll: true })}
                                                    className="rounded-lg bg-brand-600 p-2 text-white"
                                                >
                                                    <Play className="h-4 w-4" />
                                                </button>
                                            )}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AdminLayout>
    );
}
