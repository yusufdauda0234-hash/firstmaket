import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import PageHeader from '@/Components/ui/PageHeader';
import { Pagination, PaginationLink } from '@/Components/ui/Pagination';
import { Select } from '@/Components/ui/Select';
import { Textarea } from '@/Components/ui/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Bell, Mail, MessageSquare, Megaphone, Users } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface RoleOption {
    id: number;
    name: string;
    userCount: number;
}

interface SentAnnouncement {
    uuid: string;
    title: string;
    body: string;
    audience: string;
    channels: string[];
    category: string;
    recipients: number;
    sentBy: string;
    sentAt: string | null;
}

interface Props {
    roles: RoleOption[];
    sent: { data: SentAnnouncement[]; links: PaginationLink[]; total: number };
    categories: { value: string; label: string; emailLocked: boolean }[];
    reachableUsers: number;
    search: string;
    matches: { id: number; name: string; email: string }[];
    [key: string]: unknown;
}

const CHANNELS = [
    { value: 'database', label: 'In-app notification', icon: Bell, hint: 'Shows in their bell menu.' },
    { value: 'mail', label: 'Email', icon: Mail, hint: 'Goes to their registered address.' },
    { value: 'sms', label: 'SMS', icon: MessageSquare, hint: 'Verified phone numbers only. Costs money per message.' },
] as const;

/**
 * Sending a message out to the userbase.
 *
 * The composer is deliberately blunt about scale — the reach count sits next
 * to the audience picker and next to the send button, because the difference
 * between messaging one customer and messaging all of them is one radio
 * button and there is no way to unsend.
 */
export default function AdminNotifications() {
    const { roles, sent, categories, reachableUsers, search, matches } = usePage<Props>().props;

    const form = useForm({
        title: '',
        body: '',
        audience: 'all' as 'all' | 'role' | 'user',
        role_id: null as number | null,
        user_id: null as number | null,
        channels: ['database'] as string[],
        category: 'promotions',
    });

    const [query, setQuery] = useState(search);
    const [picked, setPicked] = useState<{ id: number; name: string; email: string } | null>(null);

    // Typeahead for the single-recipient audience. Debounced and partial: the
    // composer must not lose what has been typed into the message body while
    // the sender is still deciding who it goes to.
    useEffect(() => {
        if (form.data.audience !== 'user' || query.trim().length < 2 || query === search) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(
                route('admin.notifications.index'),
                { q: query },
                { only: ['matches', 'search'], preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => window.clearTimeout(timer);
    }, [query, form.data.audience, search]);

    const selectedRole = roles.find((role) => role.id === form.data.role_id) ?? null;

    const reach =
        form.data.audience === 'all'
            ? reachableUsers
            : form.data.audience === 'role'
              ? (selectedRole?.userCount ?? 0)
              : picked
                ? 1
                : 0;

    const toggleChannel = (value: string) => {
        form.setData(
            'channels',
            form.data.channels.includes(value)
                ? form.data.channels.filter((channel) => channel !== value)
                : [...form.data.channels, value],
        );
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.notifications.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setPicked(null);
                setQuery('');
            },
        });
    };

    return (
        <AdminLayout>
            <Head title="Notifications" />
            <PageHeader
                eyebrow="Communications"
                title="Notifications"
                description="Send a message to everyone, to one role, or to a single person — in-app, by email, or by SMS."
            />

            <div className="grid gap-6 lg:grid-cols-5">
                <Card className="p-5 lg:col-span-3">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <Label htmlFor="title">Title</Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(event) => form.setData('title', event.target.value)}
                                placeholder="Delivery delays in Lagos this week"
                                maxLength={120}
                            />
                            <InputError message={form.errors.title} />
                        </div>

                        <div>
                            <Label htmlFor="body">Message</Label>
                            <Textarea
                                id="body"
                                rows={5}
                                value={form.data.body}
                                onChange={(event) => form.setData('body', event.target.value)}
                                placeholder="Write it as you would say it. This exact text is what lands in their inbox."
                                maxLength={2000}
                            />
                            <div className="mt-1 flex items-center justify-between">
                                <InputError message={form.errors.body} />
                                <span className="text-xs tabular-nums text-gray-400">{form.data.body.length}/2000</span>
                            </div>
                        </div>

                        <fieldset>
                            <legend className="mb-2 text-sm font-medium text-gray-700">Who receives it</legend>
                            <div className="space-y-2">
                                <AudienceOption
                                    checked={form.data.audience === 'all'}
                                    onSelect={() => form.setData('audience', 'all')}
                                    label="Everyone"
                                    detail={`${reachableUsers.toLocaleString()} active accounts`}
                                />

                                <AudienceOption
                                    checked={form.data.audience === 'role'}
                                    onSelect={() => form.setData('audience', 'role')}
                                    label="A role"
                                    detail="Customers, vendors, or any staff role"
                                >
                                    <Select
                                        value={form.data.role_id ?? ''}
                                        onChange={(event) =>
                                            form.setData('role_id', event.target.value === '' ? null : Number(event.target.value))
                                        }
                                    >
                                        <option value="">Choose a role…</option>
                                        {roles.map((role) => (
                                            <option key={role.id} value={role.id}>
                                                {role.name} ({role.userCount.toLocaleString()})
                                            </option>
                                        ))}
                                    </Select>
                                    <InputError message={form.errors.role_id} />
                                </AudienceOption>

                                <AudienceOption
                                    checked={form.data.audience === 'user'}
                                    onSelect={() => form.setData('audience', 'user')}
                                    label="One person"
                                    detail="Search by name, email, or phone"
                                >
                                    {picked ? (
                                        <div className="flex items-center justify-between rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm">
                                            <span>
                                                <span className="font-medium text-gray-900">{picked.name}</span>
                                                <span className="ml-2 text-gray-500">{picked.email}</span>
                                            </span>
                                            <button
                                                type="button"
                                                className="text-xs font-medium text-brand-700 hover:underline"
                                                onClick={() => {
                                                    setPicked(null);
                                                    form.setData('user_id', null);
                                                }}
                                            >
                                                Change
                                            </button>
                                        </div>
                                    ) : (
                                        <>
                                            <Input
                                                value={query}
                                                onChange={(event) => setQuery(event.target.value)}
                                                placeholder="Start typing a name…"
                                            />
                                            {matches.length > 0 && (
                                                <ul className="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200">
                                                    {matches.map((match) => (
                                                        <li key={match.id}>
                                                            <button
                                                                type="button"
                                                                className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-gray-50"
                                                                onClick={() => {
                                                                    setPicked(match);
                                                                    form.setData('user_id', match.id);
                                                                }}
                                                            >
                                                                <span className="font-medium text-gray-900">{match.name}</span>
                                                                <span className="text-xs text-gray-500">{match.email}</span>
                                                            </button>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </>
                                    )}
                                    <InputError message={form.errors.user_id} />
                                </AudienceOption>
                            </div>
                            <InputError message={form.errors.audience} />
                        </fieldset>

                        <fieldset>
                            <legend className="mb-2 text-sm font-medium text-gray-700">How it is delivered</legend>
                            <div className="space-y-2">
                                {CHANNELS.map(({ value, label, icon: Icon, hint }) => (
                                    <label
                                        key={value}
                                        className="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 transition hover:border-brand-200 hover:bg-brand-50/40"
                                    >
                                        <input
                                            type="checkbox"
                                            className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                            checked={form.data.channels.includes(value)}
                                            onChange={() => toggleChannel(value)}
                                        />
                                        <span className="min-w-0">
                                            <span className="flex items-center gap-1.5 text-sm font-medium text-gray-900">
                                                <Icon className="h-4 w-4 text-gray-400" /> {label}
                                            </span>
                                            <span className="text-xs text-gray-500">{hint}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={form.errors.channels} />
                        </fieldset>

                        <div>
                            <Label htmlFor="category">Category</Label>
                            <Select
                                id="category"
                                value={form.data.category}
                                onChange={(event) => form.setData('category', event.target.value)}
                            >
                                {categories.map((category) => (
                                    <option key={category.value} value={category.value}>
                                        {category.label}
                                    </option>
                                ))}
                            </Select>
                            {/* The category is not cosmetic: it decides which of
                                the recipient's own toggles apply. Somebody who
                                turned off deals will not get this if it is filed
                                under deals — which is the correct outcome, and
                                worth saying plainly so nobody files an ad under
                                "Account security" to force it through. */}
                            <p className="mt-1 text-xs text-gray-500">
                                Each person's notification settings for this category still apply. Choose the one that honestly
                                describes the message.
                            </p>
                            <InputError message={form.errors.category} />
                        </div>

                        <div className="flex items-center justify-between border-t border-gray-100 pt-4">
                            <p className="text-sm text-gray-600">
                                <Users className="mr-1 inline h-4 w-4 text-gray-400" />
                                Reaches <span className="font-semibold tabular-nums text-gray-900">{reach.toLocaleString()}</span>{' '}
                                {reach === 1 ? 'person' : 'people'}
                            </p>
                            <Button type="submit" disabled={form.processing || reach === 0 || form.data.channels.length === 0}>
                                <Megaphone className="mr-1.5 h-4 w-4" />
                                {form.processing ? 'Sending…' : 'Send'}
                            </Button>
                        </div>
                    </form>
                </Card>

                <Card className="p-5 lg:col-span-2">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Already sent</h2>

                    {sent.data.length === 0 ? (
                        <p className="mt-4 text-sm text-gray-500">Nothing has been sent yet.</p>
                    ) : (
                        <ul className="mt-4 space-y-3">
                            {sent.data.map((announcement) => (
                                <li key={announcement.uuid} className="rounded-lg border border-gray-200 p-3">
                                    <p className="text-sm font-medium text-gray-900">{announcement.title}</p>
                                    <p className="mt-0.5 line-clamp-2 text-xs text-gray-500">{announcement.body}</p>
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                        <Badge tone="neutral">{announcement.audience}</Badge>
                                        <Badge tone="success">{announcement.recipients.toLocaleString()} sent</Badge>
                                        {announcement.channels.map((channel) => (
                                            <Badge key={channel} tone="neutral">
                                                {CHANNELS.find((c) => c.value === channel)?.label ?? channel}
                                            </Badge>
                                        ))}
                                    </div>
                                    <p className="mt-2 text-xs text-gray-400">
                                        {announcement.sentBy} · {announcement.sentAt ?? 'queued'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}

                    <Pagination links={sent.links} />
                </Card>
            </div>
        </AdminLayout>
    );
}

function AudienceOption({
    checked,
    onSelect,
    label,
    detail,
    children,
}: {
    checked: boolean;
    onSelect: () => void;
    label: string;
    detail: string;
    children?: React.ReactNode;
}) {
    return (
        <div
            className={`rounded-lg border p-3 transition ${
                checked ? 'border-brand-300 bg-brand-50/50' : 'border-gray-200'
            }`}
        >
            <label className="flex cursor-pointer items-center gap-3">
                <input
                    type="radio"
                    name="audience"
                    className="h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500"
                    checked={checked}
                    onChange={onSelect}
                />
                <span>
                    <span className="block text-sm font-medium text-gray-900">{label}</span>
                    <span className="block text-xs text-gray-500">{detail}</span>
                </span>
            </label>
            {checked && children && <div className="mt-3 pl-7">{children}</div>}
        </div>
    );
}
