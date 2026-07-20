import { Card } from '@/Components/ui/Card';
import AccountLayout from '@/Layouts/AccountLayout';
import { cn } from '@/Utils/cn';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Bell, BellRing, Check, CheckCheck, Lock, Mail, MessageSquare, Smartphone } from 'lucide-react';

interface NotificationRow {
    id: string;
    title: string;
    body: string;
    url: string | null;
    read: boolean;
    at: string;
}

interface PreferenceRow {
    category: string;
    label: string;
    emailEnabled: boolean;
    smsEnabled: boolean;
    browserEnabled: boolean;
    emailLocked: boolean;
}

interface Props {
    notifications: NotificationRow[];
    unreadCount: number;
    preferences: PreferenceRow[];
    [key: string]: unknown;
}

/** One channel toggle chip in the preference matrix. */
function ChannelToggle({
    active,
    locked,
    icon: Icon,
    label,
    onToggle,
}: {
    active: boolean;
    locked: boolean;
    icon: typeof Mail;
    label: string;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            disabled={locked}
            onClick={onToggle}
            title={locked ? 'Security emails cannot be turned off' : undefined}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition active:scale-95',
                active
                    ? 'border-brand-200 bg-brand-50 text-brand-700'
                    : 'border-gray-200 bg-white text-gray-400 hover:border-gray-300',
                locked && 'cursor-not-allowed opacity-80',
            )}
        >
            <Icon className="h-3.5 w-3.5" />
            {label}
            {locked && <Lock className="h-3 w-3" />}
        </button>
    );
}

export default function NotificationsIndex() {
    const { notifications, unreadCount, preferences } = usePage<Props>().props;
    const prefForm = useForm({});
    const readForm = useForm({});

    const updatePref = (pref: PreferenceRow, patch: Partial<PreferenceRow>) => {
        router.put(
            route('notifications.preferences.update'),
            {
                category: pref.category,
                email_enabled: patch.emailEnabled ?? pref.emailEnabled,
                sms_enabled: patch.smsEnabled ?? pref.smsEnabled,
                browser_enabled: patch.browserEnabled ?? pref.browserEnabled,
            },
            { preserveScroll: true },
        );
    };

    return (
        <AccountLayout title="Notifications">
            <Head title="Notifications" />

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-xl font-extrabold tracking-tight text-gray-900 sm:text-2xl">
                        Notifications
                        {unreadCount > 0 && (
                            <span className="rounded-full bg-brand-600 px-2.5 py-0.5 text-xs font-bold text-white">
                                {unreadCount} new
                            </span>
                        )}
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">Order updates, savings milestones, and support replies.</p>
                </div>
                {unreadCount > 0 && (
                    <button
                        type="button"
                        disabled={readForm.processing}
                        onClick={() => readForm.post(route('notifications.read-all'), { preserveScroll: true })}
                        className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-600 transition hover:border-brand-300 hover:text-brand-700 active:scale-95"
                    >
                        <CheckCheck className="h-4 w-4" /> Mark all read
                    </button>
                )}
            </div>

            <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
                {/* ── Inbox ── */}
                <Card className="self-start p-0">
                    {notifications.length === 0 ? (
                        <div className="flex flex-col items-center px-6 py-14 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-400">
                                <Bell className="h-7 w-7" />
                            </span>
                            <p className="mt-4 text-sm font-medium text-gray-900">Nothing here yet</p>
                            <p className="mt-1 max-w-sm text-sm text-gray-500">
                                Order updates and account alerts land here the moment they happen.
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {notifications.map((notification) => {
                                const inner = (
                                    <>
                                        <span
                                            className={cn(
                                                'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl',
                                                notification.read
                                                    ? 'bg-gray-100 text-gray-400'
                                                    : 'bg-brand-50 text-brand-600',
                                            )}
                                        >
                                            <BellRing className="h-4 w-4" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span
                                                className={cn(
                                                    'block truncate text-sm',
                                                    notification.read
                                                        ? 'font-medium text-gray-600'
                                                        : 'font-bold text-gray-900',
                                                )}
                                            >
                                                {notification.title}
                                            </span>
                                            <span className="mt-0.5 block text-sm text-gray-500">
                                                {notification.body}
                                            </span>
                                            <span className="mt-0.5 block text-xs text-gray-400">{notification.at}</span>
                                        </span>
                                        {!notification.read && (
                                            <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-brand-600" />
                                        )}
                                    </>
                                );

                                return (
                                    <li key={notification.id}>
                                        {notification.url ? (
                                            <Link
                                                href={notification.url}
                                                onClick={() => {
                                                    if (!notification.read) {
                                                        router.post(route('notifications.read', notification.id), {}, { preserveScroll: true });
                                                    }
                                                }}
                                                className="flex gap-3 px-5 py-4 transition hover:bg-slate-50"
                                            >
                                                {inner}
                                            </Link>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (!notification.read) {
                                                        router.post(route('notifications.read', notification.id), {}, { preserveScroll: true });
                                                    }
                                                }}
                                                className="flex w-full gap-3 px-5 py-4 text-left transition hover:bg-slate-50"
                                            >
                                                {inner}
                                            </button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Card>

                {/* ── Preferences ── */}
                <Card className="self-start">
                    <h2 className="flex items-center gap-2 text-sm font-bold text-gray-900">
                        <Check className="h-4 w-4 text-brand-600" /> Notification settings
                    </h2>
                    <p className="mt-1 text-xs text-gray-500">
                        Choose how each kind of update reaches you. SMS needs a verified phone number.
                    </p>

                    <div className="mt-4 space-y-4">
                        {preferences.map((pref) => (
                            <div key={pref.category}>
                                <p className="text-sm font-semibold text-gray-900">{pref.label}</p>
                                <div className="mt-1.5 flex flex-wrap gap-1.5">
                                    <ChannelToggle
                                        active={pref.emailEnabled || pref.emailLocked}
                                        locked={pref.emailLocked}
                                        icon={Mail}
                                        label="Email"
                                        onToggle={() => updatePref(pref, { emailEnabled: !pref.emailEnabled })}
                                    />
                                    <ChannelToggle
                                        active={pref.smsEnabled}
                                        locked={false}
                                        icon={Smartphone}
                                        label="SMS"
                                        onToggle={() => updatePref(pref, { smsEnabled: !pref.smsEnabled })}
                                    />
                                    <ChannelToggle
                                        active={pref.browserEnabled}
                                        locked={false}
                                        icon={MessageSquare}
                                        label="In-app"
                                        onToggle={() => updatePref(pref, { browserEnabled: !pref.browserEnabled })}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                    {prefForm.processing && <p className="mt-3 text-xs text-gray-400">Saving…</p>}
                </Card>
            </div>
        </AccountLayout>
    );
}
