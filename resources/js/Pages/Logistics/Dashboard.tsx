import { FailureReason, Stop } from '@/Components/domain/logistics/StopCard';
import StopList from '@/Components/domain/logistics/StopList';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, Package } from 'lucide-react';

interface Props {
    stops: Stop[];
    failureReasons: FailureReason[];
    stats: {
        carrying: number;
        deliveredToday: number;
        failedToday: number;
        oldestWaitingDays: number;
    };
    courier: { name: string; vehicle: string | null };
    [key: string]: unknown;
}

/**
 * The courier's home screen: their live round, not a summary of it.
 *
 * This page used to be a stub with a hardcoded zero and the words "delivery
 * status updates arrive in Sprint 6", so a courier signing in saw nothing
 * they could act on.
 *
 * Single column and mobile-first on purpose — nobody does this job at a desk.
 */
export default function LogisticsDashboard() {
    const {
        stops = [],
        failureReasons = [],
        stats = { carrying: 0, deliveredToday: 0, failedToday: 0, oldestWaitingDays: 0 },
        courier,
    } = usePage<Props>().props;

    return (
        <AdminLayout>
            <Head title="My deliveries" />

            <div className="mx-auto max-w-3xl">
                <header>
                    <h1 className="text-2xl font-extrabold tracking-tight text-gray-900">
                        {greeting()}, {courier?.name?.split(' ')[0] ?? 'there'}
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        {stats.carrying === 0
                            ? 'Nothing on your round yet today.'
                            : `${stats.carrying} parcel${stats.carrying === 1 ? '' : 's'} on your round`}
                        {courier?.vehicle && ` · ${courier.vehicle}`}
                    </p>
                </header>

                <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Stat label="Carrying" value={stats.carrying} icon={Package} tone="text-gray-700" />
                    <Stat
                        label="Delivered today"
                        value={stats.deliveredToday}
                        icon={CheckCircle2}
                        tone="text-emerald-600"
                    />
                    <Stat
                        label="Failed today"
                        value={stats.failedToday}
                        icon={AlertTriangle}
                        tone={stats.failedToday > 0 ? 'text-amber-600' : 'text-gray-400'}
                    />
                    {/* The oldest parcel still on the van is the one closest to
                        becoming a complaint, so it gets its own number. */}
                    <Stat
                        label="Oldest waiting"
                        value={stats.oldestWaitingDays === 0 ? '—' : `${stats.oldestWaitingDays}d`}
                        icon={Clock}
                        tone={stats.oldestWaitingDays >= 3 ? 'text-red-600' : 'text-gray-400'}
                    />
                </div>

                <h2 className="mb-3 mt-8 text-sm font-bold uppercase tracking-wide text-gray-500">
                    Your round
                </h2>

                <StopList stops={stops} failureReasons={failureReasons} />
            </div>
        </AdminLayout>
    );
}

function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';

    return 'Good evening';
}

function Stat({
    label,
    value,
    icon: Icon,
    tone,
}: {
    label: string;
    value: number | string;
    icon: typeof Package;
    tone: string;
}) {
    return (
        <div className="rounded-2xl border border-gray-100 bg-white p-3.5 shadow-sm">
            <Icon className={`h-4 w-4 ${tone}`} />
            <p className={`mt-1.5 text-2xl font-extrabold tabular-nums ${tone}`}>{value}</p>
            <p className="text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                {label}
            </p>
        </div>
    );
}
