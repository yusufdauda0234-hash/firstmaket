import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import { PasswordInput } from '@/Components/ui/PasswordInput';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

// This page is always light (white panel), so neutralize the shared
// components' dark-mode variants — otherwise an OS dark theme renders
// near-black inputs on the white background.
const lightInput = 'dark:border-gray-300 dark:bg-white dark:text-gray-900';
const lightLabel = 'dark:text-gray-700';

/**
 * Vendor application page, styled like the customer /login split page:
 * brand panel on the left selling the "why", application form on the right.
 * Vendors sign in through the shared /login flow once approved.
 */
export default function RegisterVendor() {
    const { data, setData, post, processing, errors } = useForm<{
        business_name: string;
        contact_name: string;
        email: string;
        phone: string;
        address: string;
        password: string;
        password_confirmation: string;
        cac_document: File | null;
    }>({
        business_name: '',
        contact_name: '',
        email: '',
        phone: '',
        address: '',
        password: '',
        password_confirmation: '',
        cac_document: null,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('vendor.register'));
    };

    return (
        <>
            <Head title="Become a vendor" />

            <div className="flex min-h-screen bg-white">
                {/* Brand panel */}
                <div className="relative hidden flex-1 flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-700 to-brand-900 p-10 lg:flex">
                    <span
                        className="pointer-events-none absolute -bottom-10 -right-6 select-none text-[13rem] leading-none opacity-10"
                        aria-hidden="true"
                    >
                        🏪
                    </span>

                    <Link href={route('home')} aria-label="Back to FirstMaket home">
                        <img
                            src="/images/brand/logo-light-transparent.png"
                            alt="FirstMaket"
                            className="h-20 w-auto"
                        />
                    </Link>

                    <div className="relative z-[1]">
                        <p className="mb-3 inline-flex items-center gap-2 rounded-full border border-brand-yellow/40 bg-brand-yellow/10 px-4 py-1.5 text-[11px] font-bold uppercase tracking-[0.18em] text-brand-yellow">
                            Grow your business
                        </p>
                        <h1 className="max-w-md text-3xl font-extrabold text-white">
                            Sell to customers across Nigeria.
                        </h1>
                        <p className="mt-3 max-w-md text-brand-100">
                            Zero listing fees, instant Paystack payouts, and FirstMaket handles the
                            delivery. Verified vendors only — your store, our logistics.
                        </p>
                        <ul className="mt-6 space-y-2 text-sm text-brand-100">
                            {[
                                'Zero listing fees — keep more of every sale',
                                'Instant payouts through Paystack',
                                'FirstMaket delivers; you focus on selling',
                                'Verified marketplace — CAC-checked vendors only',
                            ].map((item) => (
                                <li key={item} className="flex items-center gap-2">
                                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-brand-yellow text-[11px] font-bold text-brand-900">
                                        ✓
                                    </span>
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <p className="relative z-[1] text-xs text-brand-200">
                        Applications are reviewed by our team before you can list products.
                    </p>
                </div>

                {/* Form panel */}
                <div className="flex flex-1 flex-col px-6 py-8 sm:px-12">
                    <Link href={route('home')} className="lg:hidden" aria-label="Back to FirstMaket home">
                        <img src="/images/brand/logo-mark-dark.png" alt="FirstMaket" className="h-10 w-auto" />
                    </Link>

                    <div className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center py-8">
                        <h2 className="text-center text-2xl font-bold text-gray-900">Register as a vendor</h2>
                        <p className="mt-2 text-center text-sm text-gray-500">
                            Your account is reviewed by our team before you can list products. Upload your
                            CAC registration document to complete the application.
                        </p>

                        <form onSubmit={submit} className="mt-8 space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="business_name" className={lightLabel}>Business name</Label>
                                    <Input
                                        className={lightInput}
                                        id="business_name"
                                        value={data.business_name}
                                        onChange={(e) => setData('business_name', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.business_name} />
                                </div>

                                <div>
                                    <Label htmlFor="contact_name" className={lightLabel}>Contact person</Label>
                                    <Input
                                        className={lightInput}
                                        id="contact_name"
                                        value={data.contact_name}
                                        onChange={(e) => setData('contact_name', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.contact_name} />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="email" className={lightLabel}>Business email</Label>
                                    <Input
                                        className={lightInput}
                                        id="email"
                                        type="email"
                                        autoComplete="username"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div>
                                    <Label htmlFor="phone" className={lightLabel}>Phone number</Label>
                                    <Input
                                        className={lightInput}
                                        id="phone"
                                        type="tel"
                                        placeholder="+2348012345678"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.phone} />
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="address" className={lightLabel}>Business address</Label>
                                <Input
                                    className={lightInput}
                                    id="address"
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                    required
                                />
                                <InputError message={errors.address} />
                            </div>

                            <div>
                                <Label htmlFor="cac_document" className={lightLabel}>CAC document (PDF or image, max 5MB)</Label>
                                <label
                                    htmlFor="cac_document"
                                    className="mt-1 flex cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed border-gray-300 px-4 py-4 transition hover:border-brand-400 hover:bg-brand-50/50"
                                >
                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-lg">
                                        📄
                                    </span>
                                    <span className="min-w-0 text-sm">
                                        {data.cac_document ? (
                                            <span className="block truncate font-semibold text-gray-900">
                                                {data.cac_document.name}
                                            </span>
                                        ) : (
                                            <span className="font-semibold text-brand-600">
                                                Click to upload your CAC document
                                            </span>
                                        )}
                                        <span className="block text-xs text-gray-400">
                                            PDF, JPG or PNG — up to 5MB
                                        </span>
                                    </span>
                                </label>
                                <input
                                    id="cac_document"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    className="sr-only"
                                    onChange={(e) => setData('cac_document', e.target.files?.[0] ?? null)}
                                    required
                                />
                                <InputError message={errors.cac_document} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="password" className={lightLabel}>Password</Label>
                                    <PasswordInput
                                        className={lightInput}
                                        id="password"
                                        autoComplete="new-password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div>
                                    <Label htmlFor="password_confirmation" className={lightLabel}>Confirm password</Label>
                                    <PasswordInput
                                        className={lightInput}
                                        id="password_confirmation"
                                        autoComplete="new-password"
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.password_confirmation} />
                                </div>
                            </div>

                            <Button type="submit" disabled={processing} className="w-full">
                                Submit vendor application
                            </Button>
                        </form>

                        <p className="mt-6 text-center text-sm text-gray-500">
                            Already a vendor?{' '}
                            <a href={route('vendor.login')} className="font-medium text-brand-600 hover:underline">
                                Sign in to the Vendor Center
                            </a>
                        </p>
                        <p className="mt-2 text-center text-sm text-gray-500">
                            Shopping instead?{' '}
                            <Link href={route('register')} className="font-medium text-brand-600 hover:underline">
                                Create a customer account
                            </Link>
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
