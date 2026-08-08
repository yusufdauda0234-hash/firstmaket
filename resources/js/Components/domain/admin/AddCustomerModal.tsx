import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { useForm } from '@inertiajs/react';
import { Info, UserPlus, X } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
}

/**
 * Create a customer account from the admin side — for someone who ordered over
 * the phone or at a counter and needs an account to track it.
 *
 * No password field: staff must never know a customer's credentials, so the
 * account is created with an unguessable secret and the customer sets their own
 * using a one-time code we email them.
 */
export default function AddCustomerModal({ open, onClose }: Props) {
    const form = useForm({ name: '', email: '', phone: '' });

    if (!open) {
        return null;
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('admin.users.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-900/40 p-4 py-12">
            <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <div className="flex items-start justify-between">
                    <div>
                        <h2 className="flex items-center gap-2 text-lg font-extrabold text-gray-900">
                            <UserPlus className="h-5 w-5 text-brand-600" /> Add a customer
                        </h2>
                        <p className="mt-1 text-sm text-gray-500">
                            For someone who ordered by phone or in person and needs an account.
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
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Full name</span>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="Amina Okafor"
                            required
                            autoFocus
                        />
                        <InputError message={form.errors.name} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">Email</span>
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            placeholder="amina@example.com"
                            required
                        />
                        <p className="mt-1 text-xs text-gray-400">
                            The set-your-password code goes here, so it has to be reachable.
                        </p>
                        <InputError message={form.errors.email} className="mt-1" />
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-gray-700">
                            Phone <span className="font-normal text-gray-400">optional</span>
                        </span>
                        <Input
                            type="tel"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                            placeholder="08031234567"
                        />
                        <InputError message={form.errors.phone} className="mt-1" />
                    </label>

                    <p className="flex gap-2 text-xs text-gray-500">
                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
                        We never set a password for a customer. They choose their own from a single-use link, so
                        nobody on the team ever knows it.
                    </p>

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
                            {form.processing ? 'Adding…' : 'Add customer'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
