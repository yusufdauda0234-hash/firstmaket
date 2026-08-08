import { FailureReason, Stop } from '@/Components/domain/logistics/StopCard';
import StopList from '@/Components/domain/logistics/StopList';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, usePage } from '@inertiajs/react';

interface Props {
    stops: Stop[];
    failureReasons: FailureReason[];
    [key: string]: unknown;
}

/**
 * The round on its own, without the day's figures.
 *
 * Same list the dashboard shows — a courier who taps "Deliveries" in the nav
 * should not have to scroll past statistics to reach the next address.
 */
export default function LogisticsTasks() {
    const { stops = [], failureReasons = [] } = usePage<Props>().props;

    return (
        <AdminLayout>
            <Head title="Deliveries" />

            <div className="mx-auto max-w-3xl">
                <header className="mb-5">
                    <h1 className="text-2xl font-extrabold tracking-tight text-gray-900">
                        Deliveries
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        {stops.length === 0
                            ? 'Nothing assigned to you right now.'
                            : `${stops.length} parcel${stops.length === 1 ? '' : 's'} to move.`}
                    </p>
                </header>

                <StopList stops={stops} failureReasons={failureReasons} />
            </div>
        </AdminLayout>
    );
}
