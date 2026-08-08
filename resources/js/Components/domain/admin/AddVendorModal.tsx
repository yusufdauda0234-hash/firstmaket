import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { useForm } from '@inertiajs/react';
import { Store, X } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
}

/**
 * Onboard a seller the team has already dealt with offline.
 *
 * No password field on purpose: staff should never know a vendor's
 * credentials, so the account is created with an unguessable secret and the
 * vendor sets their own using a one-time code we email them. Laravel's
 * link-based reset is not used here: this app has no `password.reset` route,
 * because every reset it does is a 6-digit code.
 */
export default function AddVendorModal({ open, onClose }: Props) {
    const form = useForm<{
        business_name: string;
        contact_name: string;
        email: string;
        phone: string;
        address: string;
        approve_now: boolean;
        cac_document: File | null;
    }>({
        business_name: '',
        contact_name: '',
        email: '',
        phone: '',
        address: '',
        approve_now: true,
        cac_document: null,
    });

    if (!open) {
        return null;
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        // forceFormData because of the optional CAC upload — a JSON body
        // cannot carry a file.
        form.post(route('admin.vendors.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-900/40 p-4 py-10">
            <div className="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl">
                <div className="flex items-start justify-between">
                    <div>
                        <h2 className="flex items-center gap-2 text-lg font-extrabold text-gray-900">
                            <Store className="h-5 w-5 text-brand-600" /> Add a vendor
                        </h2>
                        <p className="mt-1 text-sm text-gray-500">
                            For sellers onboarded by the team rather than through the public sign-up.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Business name</span>
                        <Input
                            value={form.data.business_name}
                            onChange={(e) => form.setData('business_name', e.target.value)}
                            placeholder="Bright Electronics Ltd"
                            required
                            autoFocus
                        />
                        <InputError message={form.errors.business_name} className="mt-1" />
                    </label>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="block">
                            <span className="mb-1.5 block text-xs font-bold text-gray-700">Contact name</span>
                            <Input
                                value={form.data.contact_name}
                                onChange={(e) => form.setData('contact_name', e.target.value)}
                                placeholder="Chinedu Okafor"
                                required
                            />
                            <InputError message={form.errors.contact_name} className="mt-1" />
                        </label>
                        <label className="block">
                            <span className="mb-1.5 block text-xs font-bold text-gray-700">
                                Phone <span className="font-medium text-gray-400">optional</span>
                            </span>
                            <Input
                                type="tel"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                placeholder="08031234567"
                            />
                            <InputError message={form.errors.phone} className="mt-1" />
                        </label>
                    </div>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Email</span>
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            placeholder="sales@brightelectronics.ng"
                            required
                        />
                        <p className="mt-1 text-xs text-gray-400">
                            This is where the set-your-password code goes, so it must be reachable.
                        </p>
                        <InputError message={form.errors.email} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Business address</span>
                        <Input
                            value={form.data.address}
                            onChange={(e) => form.setData('address', e.target.value)}
                            placeholder="14 Ahmadu Bello Way, Kaduna"
                            required
                        />
                        <InputError message={form.errors.address} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">
                            CAC document <span className="font-medium text-gray-400">optional</span>
                        </span>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onChange={(e) => form.setData('cac_document', e.target.files?.[0] ?? null)}
                            className="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-xs file:font-bold file:text-brand-700 hover:file:bg-brand-100"
                        />
                       
                        <InputError message={form.errors.cac_document} className="mt-1" />
                    </label>

                    <label className="flex items-start gap-2.5 rounded-xl bg-gray-50 p-3">
                        <input
                            type="checkbox"
                            checked={form.data.approve_now}
                            onChange={(e) => form.setData('approve_now', e.target.checked)}
                            className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                        />
                        <span className="text-sm">
                            <span className="font-semibold text-gray-800">Approve immediately</span>
                           
                        </span>
                    </label>
                    
                    <div className="flex justify-end gap-2 pt-1">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-500 transition hover:bg-gray-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                        >
                            {form.processing ? 'Creating…' : 'Create vendor'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
