import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

// Staff auth pages are always light (white form panel), so neutralize the
// shared components' dark-mode variants — same fix as the vendor register
// page: an OS dark theme must not render black inputs on white.
export const lightInput = 'dark:border-gray-300 dark:bg-white dark:text-gray-900';
export const lightLabel = 'dark:text-gray-700';

interface StaffAuthLayoutProps {
    title: string;
    subtitle?: string;
}

/**
 * Split-panel shell for the staff portal auth pages (login, 2FA setup),
 * mirroring the customer /login design: brand panel left, form right —
 * but staff-flavored: security messaging instead of shopping promises.
 */
export default function StaffAuthLayout({ title, subtitle, children }: PropsWithChildren<StaffAuthLayoutProps>) {
    return (
        <div className="flex min-h-screen bg-white">
            {/* Brand panel */}
            <div className="relative hidden flex-1 flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-800 to-brand-900 p-10 lg:flex">
                <span
                    className="pointer-events-none absolute -bottom-12 -right-8 select-none text-[13rem] leading-none opacity-10"
                    aria-hidden="true"
                >
                    🛡️
                </span>

                <img
                    src="/images/brand/logo-light-transparent.png"
                    alt="FirstMaket"
                    className="h-20 w-auto self-start"
                />

                <div className="relative z-[1]">
                    <p className="mb-3 inline-flex items-center gap-2 rounded-full border border-brand-yellow/40 bg-brand-yellow/10 px-4 py-1.5 text-[11px] font-bold uppercase tracking-[0.18em] text-brand-yellow">
                        Staff portal
                    </p>
                    <h1 className="max-w-md text-3xl font-extrabold text-white">
                        Run the marketplace behind the marketplace.
                    </h1>
                    <p className="mt-3 max-w-md text-brand-100">
                        Approvals, payouts, support and logistics — everything staff need to keep
                        FirstMaket moving, in one place.
                    </p>
                    <ul className="mt-6 space-y-2 text-sm text-brand-100">
                        {[
                            'Role-based access — you only see what your role allows',
                            'Two-factor authentication for privileged accounts',
                            'Every sensitive action is audit-logged',
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
                    Authorized personnel only. Sessions are monitored and logged.
                </p>
            </div>

            {/* Form panel */}
            <div className="flex flex-1 flex-col px-6 py-8 sm:px-12">
                <Link href={route('home')} className="lg:hidden" aria-label="FirstMaket">
                    <img src="/images/brand/logo-mark-dark.png" alt="FirstMaket" className="h-10 w-auto" />
                </Link>

                <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-8">
                    <h2 className="text-center text-2xl font-bold text-gray-900">{title}</h2>
                    {subtitle && <p className="mt-2 text-center text-sm text-gray-500">{subtitle}</p>}
                    <div className="mt-8">{children}</div>
                </div>
            </div>
        </div>
    );
}
