import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import { PageProps } from '@/Types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

// Always-light page: neutralize the shared components' dark-mode variants.
const lightInput = 'dark:border-gray-300 dark:bg-white dark:text-gray-900';
const lightLabel = 'dark:text-gray-700';

/**
 * Vendor Center sign-in on the vendors subdomain — split-panel like the
 * customer /login and staff portal pages, with seller-focused messaging.
 * Only vendor accounts may sign in here (LoginRequest portal guard).
 */
export default function VendorLogin() {
    const { mainSiteUrl } = usePage<PageProps>().props;

    const { data, setData, post, processing, errors } = useForm({
        identifier: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('vendor.login'));
    };

    return (
        <>
            <Head title="Vendor sign in" />

            <div className="flex min-h-screen bg-white">
                {/* Brand panel */}
                <div className="relative hidden flex-1 flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-700 to-brand-900 p-10 lg:flex">
                    <span
                        className="pointer-events-none absolute -bottom-10 -right-6 select-none text-[13rem] leading-none opacity-10"
                        aria-hidden="true"
                    >
                        🏪
                    </span>

                    <a href={mainSiteUrl} aria-label="Back to FirstMaket">
                        <img
                            src="/images/brand/logo-light-transparent.png"
                            alt="FirstMaket"
                            className="h-20 w-auto"
                        />
                    </a>

                    <div className="relative z-[1]">
                        <p className="mb-3 inline-flex items-center gap-2 rounded-full border border-brand-yellow/40 bg-brand-yellow/10 px-4 py-1.5 text-[11px] font-bold uppercase tracking-[0.18em] text-brand-yellow">
                            Vendor Center
                        </p>
                        <h1 className="max-w-md text-3xl font-extrabold text-white">
                            Your store, our logistics.
                        </h1>
                        <p className="mt-3 max-w-md text-brand-100">
                            Manage your listings, track approvals, and grow your sales across Nigeria —
                            all from one place.
                        </p>
                        <ul className="mt-6 space-y-2 text-sm text-brand-100">
                            {[
                                'Zero listing fees — keep more of every sale',
                                'Instant payouts through Paystack',
                                'FirstMarketdelivers; you focus on selling',
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
                        Vendor accounts only. Shoppers sign in on the main FirstMarketsite.
                    </p>
                </div>

                {/* Form panel */}
                <div className="flex flex-1 flex-col px-6 py-8 sm:px-12">
                    <a href={mainSiteUrl} className="lg:hidden" aria-label="Back to FirstMaket">
                        <img src="/images/brand/logo-mark-dark.png" alt="FirstMaket" className="h-10 w-auto" />
                    </a>

                    <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-8">
                        <h2 className="text-center text-2xl font-bold text-gray-900">Vendor sign in</h2>
                        <p className="mt-2 text-center text-sm text-gray-500">
                            Sign in with the email or phone number on your vendor account.
                        </p>

                        <form onSubmit={submit} className="mt-8 space-y-4">
                            <div>
                                <Label htmlFor="identifier" className={lightLabel}>
                                    Email or phone number
                                </Label>
                                <Input
                                    id="identifier"
                                    autoComplete="username"
                                    className={lightInput}
                                    value={data.identifier}
                                    onChange={(e) => setData('identifier', e.target.value)}
                                    required
                                />
                                <InputError message={errors.identifier} />
                            </div>

                            <div>
                                <Label htmlFor="password" className={lightLabel}>
                                    Password
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="current-password"
                                    className={lightInput}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    required
                                />
                                <InputError message={errors.password} />
                            </div>

                            <label className="flex cursor-pointer items-center gap-2 text-sm text-gray-600">
                                <input
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                    className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                />
                                Keep me signed in on this device
                            </label>

                            <Button
                                type="submit"
                                disabled={processing}
                                className="w-full transition hover:shadow-lg focus-visible:ring-2 focus-visible:ring-brand-yellow active:scale-[0.98]"
                            >
                                {processing ? 'Signing in…' : 'Sign in to Vendor Center'}
                            </Button>
                        </form>

                        <p className="mt-6 text-center text-sm text-gray-500">
                            Not selling yet?{' '}
                            <a href={`${mainSiteUrl}/vendor/register`} className="font-medium text-brand-600 hover:underline">
                                Apply to become a vendor
                            </a>
                        </p>
                        <p className="mt-2 text-center text-sm text-gray-500">
                            Shopping instead?{' '}
                            <a href={mainSiteUrl} className="font-medium text-brand-600 hover:underline">
                                Go to the marketplace
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
