import AccountLayout from '@/Layouts/AccountLayout';
import VendorLayout from '@/Layouts/VendorLayout';
import { PageProps } from '@/Types';
import { usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

/**
 * Chrome for the pages that belong to whoever is signed in rather than to one
 * portal — support tickets and notifications.
 *
 * Those routes carry no domain constraint, so a vendor reaches them on the
 * Vendor Center origin, under the vendor session (session cookies are
 * host-only here — see config/session.php). Rendering the storefront header
 * there would show a search bar, a cart and a categories menu that the
 * subdomain does not serve, so the page follows the origin instead.
 */
export default function PortalLayout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { portal } = usePage<PageProps>().props;

    if (portal === 'vendor') {
        return <VendorLayout title={title}>{children}</VendorLayout>;
    }

    return <AccountLayout title={title}>{children}</AccountLayout>;
}
