import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';

interface Flag { key: string; enabled: boolean }
interface Props { features: Flag[]; [key: string]: unknown }

export default function Features() {
    const { features = [] } = usePage<Props>().props;
    return <AdminLayout><Head title="Feature flags" /><PageHeader title="Feature flags" description="Enable or disable optional modules without a deploy." /><Card className="divide-y divide-gray-100 p-0">{features.map((flag) => <div key={flag.key} className="flex items-center justify-between gap-4 p-4"><div><p className="font-semibold text-gray-900">{flag.key}</p><p className="text-xs text-gray-500">Pennant-backed runtime flag</p></div><Button variant={flag.enabled ? 'primary' : 'secondary'} onClick={() => router.post(route('admin.settings.features.update'), { feature: flag.key, enabled: !flag.enabled }, { preserveScroll: true })}>{flag.enabled ? 'Enabled' : 'Disabled'}</Button></div>)}</Card></AdminLayout>;
}