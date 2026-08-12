import { PageProps } from '@/Types';
import { Link, usePage } from '@inertiajs/react';
import { Bell, LifeBuoy } from 'lucide-react';

/**
 * The two things a signed-in person needs reachable from anywhere: what
 * happened, and how to ask for help.
 *
 * Both were previously buried — notifications lived only in the account menu,
 * and raising a ticket meant finding the Support Centre from the footer. A
 * customer whose delivery has gone wrong should not have to hunt for the way
 * to say so.
 *
 * Renders nothing for guests. There is no inbox to show them, and a bell that
 * only ever says "sign in first" is furniture.
 */
export default function HeaderInbox({ tone = 'light' }: { tone?: 'light' | 'dark' }) {
    const { auth, unreadNotifications = 0, openTickets = 0 } = usePage<PageProps>().props;

    if (!auth.user) {
        return null;
    }

    const base =
        tone === 'dark'
            ? 'text-white/80 hover:bg-white/10 hover:text-white'
            : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900';

    return (
        <div className="flex items-center gap-1">
            <HeaderIcon
                href={route('support.index')}
                label="Support"
                count={openTickets}
                className={base}
                // Amber rather than red: an open ticket is in hand, not an
                // error. Red here would make every waiting reply look like
                // something had gone wrong.
                badgeClass="bg-amber-500"
            >
                <LifeBuoy className="h-5 w-5" />
            </HeaderIcon>

            <HeaderIcon
                href={route('notifications.index')}
                label="Notifications"
                count={unreadNotifications}
                className={base}
                badgeClass="bg-brand-600"
            >
                <Bell className="h-5 w-5" />
            </HeaderIcon>
        </div>
    );
}

function HeaderIcon({
    href,
    label,
    count,
    className,
    badgeClass,
    children,
}: {
    href: string;
    label: string;
    count: number;
    className: string;
    badgeClass: string;
    children: React.ReactNode;
}) {
    return (
        <Link
            href={href}
            // The count is in the accessible name, so a screen reader hears
            // "Notifications, 3 unread" rather than just "Notifications".
            aria-label={count > 0 ? `${label}, ${count} unread` : label}
            title={label}
            className={`relative flex h-10 w-10 items-center justify-center rounded-full transition ${className}`}
        >
            {children}
            {count > 0 && (
                <span
                    aria-hidden="true"
                    className={`absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold tabular-nums text-white ${badgeClass}`}
                >
                    {count > 9 ? '9+' : count}
                </span>
            )}
        </Link>
    );
}
