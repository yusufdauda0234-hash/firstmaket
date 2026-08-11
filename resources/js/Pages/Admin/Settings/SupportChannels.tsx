import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import { Select } from '@/Components/ui/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    settings: {
        chatProvider: string;
        chatPropertyId: string;
        chatWidgetId: string;
        chatEnabledForGuests: boolean;
    };
    providers: string[];
    [key: string]: unknown;
}

const PROVIDER_LABEL: Record<string, string> = {
    none: 'No live chat',
    tawk: 'Tawk.to',
    crisp: 'Crisp',
};

/**
 * Which chat provider the storefront loads.
 *
 * Deliberately a provider picker plus an id, not a box for a script tag: a
 * pasted snippet would be arbitrary third-party JavaScript running on pages
 * where customers enter payment details.
 */
export default function SupportChannelSettings() {
    const { settings, providers } = usePage<Props>().props;

    const form = useForm({
        chat_provider: settings.chatProvider,
        chat_property_id: settings.chatPropertyId,
        chat_widget_id: settings.chatWidgetId,
        chat_for_guests: settings.chatEnabledForGuests,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.settings.support-channels.update'), { preserveScroll: true });
    };

    const active = form.data.chat_provider !== 'none';

    return (
        <AdminLayout>
            <Head title="Support channels" />
            <PageHeader
                eyebrow="Phase 2C"
                title="Support channels"
                description="Live chat on the storefront. Switching provider is a settings change — no deploy."
            />

            <form onSubmit={submit} className="max-w-2xl space-y-6">
                <Card>
                    <h2 className="flex items-center gap-2 text-sm font-bold text-gray-900">
                        <MessageCircle className="h-4 w-4 text-brand-600" /> Live chat
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Conversations live on the provider's servers, not ours. Choose “No live chat”
                        to remove the widget entirely.
                    </p>

                    <div className="mt-4 space-y-4">
                        <div>
                            <label htmlFor="provider" className="mb-1.5 block text-xs font-bold text-gray-700">
                                Provider
                            </label>
                            <Select
                                id="provider"
                                value={form.data.chat_provider}
                                onChange={(event) => form.setData('chat_provider', event.target.value)}
                            >
                                {providers.map((provider) => (
                                    <option key={provider} value={provider}>
                                        {PROVIDER_LABEL[provider] ?? provider}
                                    </option>
                                ))}
                            </Select>
                            <InputError message={form.errors.chat_provider} className="mt-1" />
                        </div>

                        {active && (
                            <>
                                <div>
                                    <label
                                        htmlFor="property_id"
                                        className="mb-1.5 block text-xs font-bold text-gray-700"
                                    >
                                        {form.data.chat_provider === 'crisp' ? 'Website ID' : 'Property ID'}
                                    </label>
                                    <Input
                                        id="property_id"
                                        value={form.data.chat_property_id}
                                        onChange={(event) =>
                                            form.setData('chat_property_id', event.target.value)
                                        }
                                        placeholder="From your provider dashboard"
                                    />
                                    <InputError message={form.errors.chat_property_id} className="mt-1" />
                                    <p className="mt-1 text-xs text-gray-400">
                                        The id only — letters, numbers, dashes and underscores. Not a
                                        script tag.
                                    </p>
                                </div>

                                {form.data.chat_provider === 'tawk' && (
                                    <div>
                                        <label
                                            htmlFor="widget_id"
                                            className="mb-1.5 block text-xs font-bold text-gray-700"
                                        >
                                            Widget ID
                                        </label>
                                        <Input
                                            id="widget_id"
                                            value={form.data.chat_widget_id}
                                            onChange={(event) =>
                                                form.setData('chat_widget_id', event.target.value)
                                            }
                                            placeholder="default"
                                        />
                                        <InputError message={form.errors.chat_widget_id} className="mt-1" />
                                    </div>
                                )}

                                <label className="flex cursor-pointer items-start gap-2.5">
                                    <input
                                        type="checkbox"
                                        checked={form.data.chat_for_guests}
                                        onChange={(event) =>
                                            form.setData('chat_for_guests', event.target.checked)
                                        }
                                        className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-2 focus:ring-brand-500/30"
                                    />
                                    <span className="text-sm text-gray-700">
                                        Show chat to signed-out visitors
                                        <span className="mt-0.5 block text-xs text-gray-400">
                                            Turn off to offer chat only to signed-in customers, which cuts
                                            spam considerably.
                                        </span>
                                    </span>
                                </label>
                            </>
                        )}
                    </div>
                </Card>

                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save support channels'}
                </Button>
            </form>
        </AdminLayout>
    );
}
